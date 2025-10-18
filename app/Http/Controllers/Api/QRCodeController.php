<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class QRCodeController extends Controller
{
    /**
     * Lista de QR Codes dinâmicos da tabela solicitacoes com cache Redis
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user() ?? ($request->user_auth ?? null);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $page = max((int) $request->get('page', 1), 1);
            $limit = (int) $request->get('limit', 20);
            if ($limit <= 0 || $limit > 100) { $limit = 20; }

            $busca = trim((string) $request->get('busca', ''));
            $dataInicio = $request->get('data_inicio');
            $dataFim = $request->get('data_fim');
            $status = $request->get('status');

            // Cache key baseado em todos os parâmetros
            $cacheKey = sprintf(
                'qrcodes:dynamic:%s:%d:%d:%s:%s:%s:%s',
                $user->username,
                $page,
                $limit,
                $status ?: 'all',
                $dataInicio ?: 'null',
                $dataFim ?: 'null',
                md5($busca ?: '')
            );

            // Cache por 2 minutos para dados dinâmicos
            $cacheTtl = 120;

            $payload = Cache::remember($cacheKey, $cacheTtl, function () use ($user, $page, $limit, $busca, $dataInicio, $dataFim, $status) {
                // Query otimizada usando UNION ALL para buscar em ambas as tabelas
                $query = $this->buildOptimizedQuery($user->username, $busca, $dataInicio, $dataFim, $status);
                
                // Contar total
                $totalQuery = clone $query;
                $total = $totalQuery->count();
                
                // Paginação
                $offset = ($page - 1) * $limit;
                $items = $query->offset($offset)->limit($limit)->get();
                
                // Formatar dados
                $formattedItems = $items->map(function ($item) {
                    return $this->formatQRCodeItem($item);
                });

                return [
                    'data' => $formattedItems,
                    'current_page' => $page,
                    'last_page' => max((int) ceil($total / $limit), 1),
                    'per_page' => $limit,
                    'total' => $total,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $limit, $total),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $payload
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao listar QR Codes dinâmicos', [
                'error' => $e->getMessage(),
                'user_id' => $user->username ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Construir query otimizada usando UNION ALL
     */
    private function buildOptimizedQuery($username, $busca, $dataInicio, $dataFim, $status)
    {
        // Query para solicitacoes (depósitos/QR Codes)
        $depositosQuery = DB::table('solicitacoes')
            ->select([
                'id',
                'idTransaction as transaction_id',
                'amount as valor',
                'descricao_transacao as descricao',
                'date as data_criacao',
                'status',
                'created_at',
                'updated_at',
                'method as tipo_cobranca',
                'client_name as devedor',
                'client_document as documento',
                DB::raw("'deposito' as origem")
            ])
            ->where('user_id', $username)
            ->whereNotNull('idTransaction');

        // Query para solicitacoes_cash_out (saques)
        $saquesQuery = DB::table('solicitacoes_cash_out')
            ->select([
                'id',
                'idTransaction as transaction_id',
                'amount as valor',
                'descricao_transacao as descricao',
                'date as data_criacao',
                'status',
                'created_at',
                'updated_at',
                'type as tipo_cobranca',
                'beneficiaryname as devedor',
                'beneficiarydocument as documento',
                DB::raw("'saque' as origem")
            ])
            ->where('user_id', $username)
            ->whereNotNull('idTransaction');

        // Aplicar filtros de data
        if ($dataInicio) {
            $depositosQuery->whereDate('date', '>=', $dataInicio);
            $saquesQuery->whereDate('date', '>=', $dataInicio);
        }
        if ($dataFim) {
            $depositosQuery->whereDate('date', '<=', $dataFim);
            $saquesQuery->whereDate('date', '<=', $dataFim);
        }

        // Aplicar filtro de status
        if ($status) {
            $depositosQuery->where('status', $status);
            $saquesQuery->where('status', $status);
        }

        // Aplicar busca
        if ($busca !== '') {
            $depositosQuery->where(function ($q) use ($busca) {
                $q->where('idTransaction', 'like', "%{$busca}%")
                  ->orWhere('descricao_transacao', 'like', "%{$busca}%");
            });
            $saquesQuery->where(function ($q) use ($busca) {
                $q->where('idTransaction', 'like', "%{$busca}%")
                  ->orWhere('descricao_transacao', 'like', "%{$busca}%");
            });
        }

        // UNION ALL (mais rápido que UNION)
        $unionQuery = $depositosQuery->unionAll($saquesQuery);
        
        // Query final com ordenação
        return DB::table(DB::raw("({$unionQuery->toSql()}) as qr_codes"))
            ->mergeBindings($unionQuery)
            ->orderBy('data_criacao', 'desc');
    }

    /**
     * Formatar item de QR Code
     */
    private function formatQRCodeItem($item)
    {
        return [
            'id' => (int) $item->id,
            'nome' => $item->descricao ?: 'QR Code PIX',
            'descricao' => $item->descricao ?: 'Transação PIX',
            'valor' => (float) $item->valor,
            'tipo' => $item->tipo ?? 'cobranca',
            'status' => $this->mapStatus($item->status),
            'data_criacao' => Carbon::parse($item->data_criacao)->format('Y-m-d H:i:s'),
            'expires_at' => Carbon::parse($item->data_criacao)->addHours(24)->format('Y-m-d H:i:s'),
            'transaction_id' => $item->transaction_id,
            'qr_code' => null, // Não armazenamos o QR code em si
            'qr_code_image_url' => null, // Não armazenamos a imagem
            'tipo_cobranca' => $item->tipo_cobranca ?? 'pix',
            'devedor' => $item->devedor ?? null,
            'documento' => $item->documento ?? null,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
            'origem' => $item->origem,
        ];
    }

    /**
     * Mapear status para formato da listagem
     */
    private function mapStatus($status)
    {
        $statusMap = [
            'PAID_OUT' => 'ativo',
            'COMPLETED' => 'ativo',
            'PENDING' => 'ativo',
            'PROCESSING' => 'ativo',
            'FAILED' => 'inativo',
            'CANCELLED' => 'inativo',
            'EXPIRED' => 'expirado',
        ];

        return $statusMap[$status] ?? 'ativo';
    }

    /**
     * Limpar cache do usuário
     */
    private function clearUserCache($username)
    {
        $pattern = "qrcodes:dynamic:{$username}:*";
        $keys = Cache::getRedis()->keys($pattern);
        if (!empty($keys)) {
            Cache::getRedis()->del($keys);
        }
    }
}
