<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class PixInfracoesController extends Controller
{
    /**
     * Lista de infrações Pix com paginação e filtros
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

            // Cache Redis para dados de infrações (TTL: 2 minutos)
            $cacheKey = sprintf(
                'pix_infracoes:%s:%d:%d:%s:%s:%s:%s',
                $user->username,
                $page,
                $limit,
                $dataInicio ?: 'null',
                $dataFim ?: 'null',
                md5($busca ?: ''),
                $request->get('cursor', 'null')
            );

            $payload = Cache::remember($cacheKey, 120, function () use ($user, $page, $limit, $busca, $dataInicio, $dataFim) {
                $query = DB::table('pix_infracoes')
                    ->where('user_id', $user->username);

                // Aplicar filtro de data (usar data_criacao ou created_at como fallback)
                if ($dataInicio) {
                    $query->whereRaw('DATE(COALESCE(data_criacao, created_at)) >= ?', [$dataInicio]);
                }
                if ($dataFim) {
                    $query->whereRaw('DATE(COALESCE(data_criacao, created_at)) <= ?', [$dataFim]);
                }
                
                // Busca apenas por end_to_end
                if ($busca !== '') {
                    $query->where('end_to_end', 'like', "%{$busca}%");
                }

                // Contar total
                $total = $query->count();
                $lastPage = max((int) ceil($total / $limit), 1);
                $offset = ($page - 1) * $limit;

                // keyset pagination (cursor) opcional
                $cursor = request()->get('cursor');
                if ($cursor) {
                    $query->whereRaw('COALESCE(data_criacao, created_at) < ?', [Carbon::parse($cursor)]);
                    $offset = 0; // com cursor, não usamos offset
                }

                // Ordenar por data_criacao ou created_at (decrescente)
                $query->orderByDesc(DB::raw('COALESCE(data_criacao, created_at)'));
                
                if ($offset > 0) {
                    $query->offset($offset);
                }
                
                $rows = $query->limit($limit)->get();

                $data = $rows->map(function ($r) {
                    // Usar data_criacao se existir, senão usar created_at
                    $dataCriacao = $r->data_criacao 
                        ? Carbon::parse($r->data_criacao) 
                        : Carbon::parse($r->created_at);
                    
                    // Usar data_limite se existir, senão calcular (7 dias após criação)
                    $dataLimite = $r->data_limite 
                        ? Carbon::parse($r->data_limite) 
                        : $dataCriacao->copy()->addDays(7);
                    
                    // Mapear status para legível
                    $statusLegivel = $this->getStatusLegivel($r->status);
                    
                    return [
                        'id' => (int) ($r->id ?? 0),
                        'status' => $statusLegivel,
                        'data_criacao' => $dataCriacao->toDateString(),
                        'data_limite' => $dataLimite->toDateString(),
                        'valor' => (float) ($r->valor ?? 0),
                        'end_to_end' => (string) ($r->end_to_end ?? $r->transaction_id ?? ''),
                        'tipo' => (string) ($r->tipo ?? 'pix'),
                        'descricao' => (string) ($r->descricao ?? ''),
                    ];
                })->toArray();

                return [
                    'data' => $data,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $limit,
                    'total' => $total,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $limit, $total),
                    'next_cursor' => count($rows) === $limit && !empty($rows) 
                        ? (string) (end($rows)->data_criacao ?? end($rows)->created_at) 
                        : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $payload
            ])->header('Access-Control-Allow-Origin', '*');
        } catch (\Exception $e) {
            Log::error('Erro ao listar infrações Pix', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Detalhes de uma infração Pix
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user() ?? ($request->user_auth ?? null);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Cache Redis para detalhes de infração (TTL: 5 minutos)
            $cacheKey = "pix_infracao_detail:{$user->username}:{$id}";
            
            $data = Cache::remember($cacheKey, 300, function () use ($user, $id) {
                $row = DB::table('pix_infracoes')
                    ->where('user_id', $user->username)
                    ->where('id', $id)
                    ->first();

                if (!$row) {
                    return null; // Retorna null se não encontrar
                }

                // Usar data_criacao se existir, senão usar created_at
                $dataCriacao = $row->data_criacao 
                    ? Carbon::parse($row->data_criacao) 
                    : Carbon::parse($row->created_at);
                
                // Usar data_limite se existir, senão calcular (7 dias após criação)
                $dataLimite = $row->data_limite 
                    ? Carbon::parse($row->data_limite) 
                    : $dataCriacao->copy()->addDays(7);
                
                // Mapear status para legível
                $statusLegivel = $this->getStatusLegivel($row->status);
                
                // Buscar transação relacionada se houver transaction_id
                $transacaoRelacionada = null;
                if ($row->transaction_id) {
                    $transacao = DB::table('solicitacoes')
                        ->where('idTransaction', $row->transaction_id)
                        ->orWhere('externalreference', $row->transaction_id)
                        ->first();
                    
                    if ($transacao) {
                        $transacaoRelacionada = [
                            'id' => (int) ($transacao->id ?? 0),
                            'transaction_id' => (string) ($transacao->idTransaction ?? $transacao->externalreference ?? ''),
                            'valor' => (float) ($transacao->amount ?? 0),
                            'data' => Carbon::parse($transacao->date ?? $transacao->created_at)->toIso8601String(),
                        ];
                    }
                }
                
                return [
                    'id' => (int) ($row->id ?? 0),
                    'status' => $statusLegivel,
                    'data_criacao' => $dataCriacao->toIso8601String(),
                    'data_limite' => $dataLimite->toIso8601String(),
                    'valor' => (float) ($row->valor ?? 0),
                    'end_to_end' => (string) ($row->end_to_end ?? $row->transaction_id ?? ''),
                    'tipo' => (string) ($row->tipo ?? 'pix'),
                    'descricao' => (string) ($row->descricao ?? ''),
                    'detalhes' => (string) ($row->detalhes ?? ''),
                    'transacao_relacionada' => $transacaoRelacionada,
                    'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                    'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
                ];
            });

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Infração não encontrada'
                ], 404)->header('Access-Control-Allow-Origin', '*');
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ])->header('Access-Control-Allow-Origin', '*');
        } catch (\Exception $e) {
            Log::error('Erro ao obter detalhe de infração Pix', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Mapear status para legível
     */
    private function getStatusLegivel(string $status): string
    {
        $statusMap = [
            'PENDENTE' => 'Pendente',
            'EM_ANALISE' => 'Em Análise',
            'RESOLVIDA' => 'Resolvida',
            'CANCELADA' => 'Cancelada',
            'CHARGEBACK' => 'Chargeback',
            'MEDIATION' => 'Mediação',
            'DISPUTE' => 'Disputa',
        ];

        return $statusMap[strtoupper($status)] ?? ucfirst(strtolower($status));
    }
}


