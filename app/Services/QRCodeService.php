<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class QRCodeService
{
    /**
     * Cache TTL para QR Codes (2 minutos)
     */
    const CACHE_TTL = 120;

    /**
     * Obter lista de QR Codes com filtros e paginação
     * 
     * @param string $username
     * @param array $filters
     * @return array
     */
    public function getQRCodes(string $username, array $filters = []): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $limit = min(max((int) ($filters['limit'] ?? 20), 1), 100);
        $busca = trim((string) ($filters['busca'] ?? ''));
        $dataInicio = $filters['data_inicio'] ?? null;
        $dataFim = $filters['data_fim'] ?? null;
        $status = $filters['status'] ?? null;

        $cacheKey = sprintf(
            'qrcodes:dynamic:%s:%d:%d:%s:%s:%s:%s',
            $username,
            $page,
            $limit,
            $status ?: 'all',
            $dataInicio ?: 'null',
            $dataFim ?: 'null',
            md5($busca ?: '')
        );

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($username, $page, $limit, $busca, $dataInicio, $dataFim, $status) {
            return $this->buildQRCodeData($username, $page, $limit, $busca, $dataInicio, $dataFim, $status);
        });
    }

    /**
     * Construir dados de QR Code
     * 
     * @param string $username
     * @param int $page
     * @param int $limit
     * @param string $busca
     * @param string|null $dataInicio
     * @param string|null $dataFim
     * @param string|null $status
     * @return array
     */
    private function buildQRCodeData(string $username, int $page, int $limit, string $busca, ?string $dataInicio, ?string $dataFim, ?string $status): array
    {
        $query = $this->buildOptimizedQuery($username, $busca, $dataInicio, $dataFim, $status);
        
        $total = $query->count();
        $offset = ($page - 1) * $limit;
        $items = $query->offset($offset)->limit($limit)->get();
        
        $formattedItems = $items->map(function ($item) {
            return $this->formatQRCodeItem($item);
        });

        return [
            'data' => $formattedItems->toArray(),
            'current_page' => $page,
            'last_page' => max((int) ceil($total / $limit), 1),
            'per_page' => $limit,
            'total' => $total,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $limit, $total),
        ];
    }

    /**
     * Construir query otimizada usando UNION ALL
     * 
     * @param string $username
     * @param string $busca
     * @param string|null $dataInicio
     * @param string|null $dataFim
     * @param string|null $status
     * @return \Illuminate\Database\Query\Builder
     */
    private function buildOptimizedQuery(string $username, string $busca, ?string $dataInicio, ?string $dataFim, ?string $status)
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
                  ->orWhere('descricao_transacao', 'like', "%{$busca}%")
                  ->orWhere('client_name', 'like', "%{$busca}%")
                  ->orWhere('client_document', 'like', "%{$busca}%");
            });
            $saquesQuery->where(function ($q) use ($busca) {
                $q->where('idTransaction', 'like', "%{$busca}%")
                  ->orWhere('descricao_transacao', 'like', "%{$busca}%")
                  ->orWhere('beneficiaryname', 'like', "%{$busca}%")
                  ->orWhere('beneficiarydocument', 'like', "%{$busca}%");
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
    private function formatQRCodeItem($item): array
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
            'qr_code' => null,
            'qr_code_image_url' => null,
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
    private function mapStatus(?string $status): string
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
    public function clearUserCache(string $username): void
    {
        try {
            $store = Cache::getStore();
            
            if (method_exists($store, 'tags')) {
                Cache::tags(['qrcodes', 'dynamic', $username])->flush();
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Erro ao limpar cache de QR codes', [
                'username' => $username,
                'error' => $e->getMessage()
            ]);
        }
    }
}















