<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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

            $query = DB::table('solicitacoes')
                ->where('user_id', $user->username)
                ->whereIn('status', ['MEDIATION', 'CHARGEBACK', 'DISPUTE']);

            if ($dataInicio) {
                $query->whereDate('created_at', '>=', $dataInicio);
            }
            if ($dataFim) {
                $query->whereDate('created_at', '<=', $dataFim);
            }
            if ($busca !== '') {
                $query->where(function ($q) use ($busca) {
                    $q->where('transaction_id', 'like', "%{$busca}%")
                      ->orWhere('descricao', 'like', "%{$busca}%")
                      ->orWhere('descricao_normalizada', 'like', "%{$busca}%")
                      ->orWhere('codigo_autenticacao', 'like', "%{$busca}%");
                });
            }

            // total opcional + cache
            $withTotal = (bool) filter_var($request->get('with_total', true), FILTER_VALIDATE_BOOLEAN);
            $total = 0;
            if ($withTotal) {
                $cacheKey = sprintf('inf_total:%s:%s:%s:%s', $user->username, $dataInicio ?: 'null', $dataFim ?: 'null', md5($busca ?: ''));
                $total = cache()->remember($cacheKey, 60, function () use ($query) {
                    return (clone $query)->count();
                });
            }
            $lastPage = $withTotal ? max((int) ceil($total / $limit), 1) : $page; // se não pedir total, evita custo
            $offset = ($page - 1) * $limit;

            // keyset pagination (cursor) opcional
            $cursor = $request->get('cursor');
            if ($cursor) {
                $query->where('created_at', '<', Carbon::parse($cursor));
                $offset = 0; // com cursor, não usamos offset
            }

            $rows = $query->orderByDesc('created_at')->limit($limit)->get();

            $data = $rows->map(function ($r) {
                $created = Carbon::parse($r->created_at);
                return [
                    'id' => (int) ($r->id ?? 0),
                    'status' => (string) ($r->status_legivel ?? $r->status ?? 'PENDING'),
                    'data_criacao' => $created->toDateString(),
                    'data_limite' => $created->copy()->addDays(7)->toDateString(),
                    'valor' => (float) ($r->amount ?? 0),
                    'end_to_end' => (string) ($r->codigo_autenticacao ?? $r->transaction_id ?? ''),
                    'tipo' => (string) ($r->tipo ?? 'pix'),
                    'descricao' => (string) ($r->descricao ?? ''),
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $data,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $limit,
                    'total' => $total,
                    'from' => $withTotal ? ($total ? $offset + 1 : 0) : null,
                    'to' => $withTotal ? ($total ? min($offset + $limit, $total) : 0) : null,
                    'next_cursor' => count($rows) === $limit ? (string) (end($rows)->created_at) : null,
                ],
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

            $row = DB::table('solicitacoes')
                ->where('user_id', $user->username)
                ->where('id', $id)
                ->whereIn('status', ['MEDIATION', 'CHARGEBACK', 'DISPUTE'])
                ->first();

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Infração não encontrada'
                ], 404)->header('Access-Control-Allow-Origin', '*');
            }

            $created = Carbon::parse($row->created_at);
            $data = [
                'id' => (int) ($row->id ?? 0),
                'status' => (string) ($row->status_legivel ?? $row->status ?? 'PENDING'),
                'data_criacao' => $created->toIso8601String(),
                'data_limite' => $created->copy()->addDays(7)->toIso8601String(),
                'valor' => (float) ($row->amount ?? 0),
                'end_to_end' => (string) ($row->codigo_autenticacao ?? $row->transaction_id ?? ''),
                'tipo' => (string) ($row->tipo ?? 'pix'),
                'descricao' => (string) ($row->descricao ?? ''),
                'detalhes' => (string) ($row->detalhes ?? ''),
                'transacao_relacionada' => $row->transaction_id ? [
                    'id' => (int) ($row->id ?? 0),
                    'transaction_id' => (string) $row->transaction_id,
                    'valor' => (float) ($row->amount ?? 0),
                    'data' => $created->toIso8601String(),
                ] : null,
                'created_at' => $created->toIso8601String(),
                'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
            ];

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
}


