<?php

/**
 * Infrações Pix (MED — Mecanismo Especial de Devolução).
 *
 * Listagem/detalhe leem a tabela local `pix_infracoes` (alimentada pelo webhook
 * INFRACTION da Treeal Contas). A defesa é encaminhada à adquirente via
 * TreealContasInfractionService (POST /infractions/{id}/defense).
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TreealContas\TreealContasInfractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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
                    
                    $analysisResult = $this->extractAnalysisResult($r);
                    $statusLegivel = $this->getStatusLegivel((string) $r->status, $analysisResult);
                    $desfecho = $this->getDesfecho($analysisResult, (string) $r->status);

                    return [
                        'id' => (int) ($r->id ?? 0),
                        'status' => $statusLegivel,
                        'desfecho_titulo' => $desfecho['titulo'] ?? null,
                        'desfecho_mensagem' => $desfecho['mensagem'] ?? null,
                        'favoravel_lojista' => $desfecho['favoravel_lojista'] ?? null,
                        'data_criacao' => $dataCriacao->toDateString(),
                        'data_limite' => $dataLimite->toDateString(),
                        'valor' => (float) ($r->valor ?? 0),
                        'end_to_end' => (string) ($r->end_to_end ?? $r->transaction_id ?? ''),
                        'tipo' => (string) ($r->tipo ?? 'pix'),
                        'tipo_legivel' => $this->getTipoLegivel((string) ($r->tipo ?? 'pix')),
                        'descricao' => (string) ($r->descricao ?? ''),
                    ];
                })->toArray();

                $lastRow = $rows->last();
                $nextCursor = ($rows->count() === $limit && $lastRow)
                    ? (string) ($lastRow->data_criacao ?? $lastRow->created_at)
                    : null;

                return [
                    'data' => $data,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $limit,
                    'total' => $total,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $limit, $total),
                    'next_cursor' => $nextCursor,
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
                
                $analysisResult = $this->extractAnalysisResult($row);
                $statusLegivel = $this->getStatusLegivel((string) $row->status, $analysisResult);
                $desfecho = $this->getDesfecho($analysisResult, (string) $row->status);

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
                    'desfecho_titulo' => $desfecho['titulo'] ?? null,
                    'desfecho_mensagem' => $desfecho['mensagem'] ?? null,
                    'favoravel_lojista' => $desfecho['favoravel_lojista'] ?? null,
                    'data_criacao' => $dataCriacao->toIso8601String(),
                    'data_limite' => $dataLimite->toIso8601String(),
                    'valor' => (float) ($row->valor ?? 0),
                    'end_to_end' => (string) ($row->end_to_end ?? $row->transaction_id ?? ''),
                    'tipo' => (string) ($row->tipo ?? 'pix'),
                    'tipo_legivel' => $this->getTipoLegivel((string) ($row->tipo ?? 'pix')),
                    'descricao' => (string) ($row->descricao ?? ''),
                    'detalhes' => (string) ($row->detalhes ?? ''),
                    'detalhes_adicionais' => $this->buildDetalhesAdicionais((string) ($row->detalhes ?? '')),
                    'pode_apresentar_defesa' => $this->canPresentDefense($row),
                    'defesa_enviada_para' => 'Treeal (adquirente Pix / MED)',
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
     * Submete uma defesa contra a infração na adquirente (Treeal Contas).
     */
    public function defense(Request $request, $id)
    {
        try {
            $user = $request->user() ?? ($request->user_auth ?? null);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $validated = $request->validate([
                'defense' => ['required', 'string', 'min:3', 'max:5000'],
                'files' => ['sometimes', 'array', 'max:10'],
                'files.*' => ['file', 'max:10240'],
            ]);

            $row = DB::table('pix_infracoes')
                ->where('user_id', $user->username)
                ->where('id', $id)
                ->first();

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Infração não encontrada'
                ], 404)->header('Access-Control-Allow-Origin', '*');
            }

            $providerId = Schema::hasColumn('pix_infracoes', 'provider_infraction_id')
                ? trim((string) ($row->provider_infraction_id ?? ''))
                : '';

            if ($providerId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta infração não possui vínculo com a adquirente para envio de defesa.'
                ], 422)->header('Access-Control-Allow-Origin', '*');
            }

            $service = app(TreealContasInfractionService::class);
            $files = $request->file('files') ?? [];
            if (! is_array($files)) {
                $files = [$files];
            }

            $result = $service->submitDefense($providerId, (string) $validated['defense'], $files);

            if (! ($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Falha ao enviar defesa para a adquirente.'
                ], 502)->header('Access-Control-Allow-Origin', '*');
            }

            DB::table('pix_infracoes')->where('id', $row->id)->update([
                'status' => 'EM_ANALISE',
                'updated_at' => Carbon::now(),
            ]);

            Cache::forget("pix_infracao_detail:{$user->username}:{$id}");
            try {
                app(\App\Services\PaymentProcessingService::class)->invalidateInfractionCaches($user->username);
            } catch (\Throwable $e) {
                // cache best-effort
            }

            return response()->json([
                'success' => true,
                'message' => 'Defesa enviada com sucesso.',
                'data' => $result['raw'] ?? null,
            ])->header('Access-Control-Allow-Origin', '*');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422)->header('Access-Control-Allow-Origin', '*');
        } catch (\Exception $e) {
            Log::error('Erro ao enviar defesa de infração Pix', [
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
     * Status legível para o lojista.
     * RESOLVIDA + DISAGREED = lojista ganhou; RESOLVIDA + AGREED = pagador ganhou (estorno).
     */
    private function getStatusLegivel(string $status, ?string $analysisResult = null): string
    {
        $statusUpper = strtoupper(trim($status));

        if ($statusUpper === 'RESOLVIDA') {
            return match (strtoupper(trim((string) $analysisResult))) {
                'AGREED' => 'Estorno',
                'DISAGREED' => 'Resolvida',
                default => 'Resolvida',
            };
        }

        $statusMap = [
            'PENDENTE' => 'Pendente',
            'EM_ANALISE' => 'Em Análise',
            'CANCELADA' => 'Cancelada',
            'CHARGEBACK' => 'Chargeback',
            'MEDIATION' => 'Mediação',
            'DISPUTE' => 'Disputa',
        ];

        return $statusMap[$statusUpper] ?? ucfirst(strtolower($status));
    }

    /**
     * @return array{titulo: string, mensagem: string, favoravel_lojista: bool|null}|null
     */
    private function getDesfecho(?string $analysisResult, string $status): ?array
    {
        if (strtoupper(trim($status)) !== 'RESOLVIDA') {
            return null;
        }

        return match (strtoupper(trim((string) $analysisResult))) {
            'DISAGREED' => [
                'titulo' => 'Contestação encerrada a seu favor',
                'mensagem' => 'A Treeal não confirmou a fraude. O valor saiu da mediação e permanece no seu saldo disponível.',
                'favoravel_lojista' => true,
            ],
            'AGREED' => [
                'titulo' => 'Valor estornado ao pagador',
                'mensagem' => 'A contestação foi aceita no MED. O Pix foi devolvido ao pagador e o valor foi debitado do seu saldo.',
                'favoravel_lojista' => false,
            ],
            default => [
                'titulo' => 'Infração encerrada',
                'mensagem' => 'Esta infração foi finalizada pela adquirente. Consulte os detalhes abaixo.',
                'favoravel_lojista' => null,
            ],
        };
    }

    private function extractAnalysisResult(object $row): ?string
    {
        if (Schema::hasColumn('pix_infracoes', 'analysis_result')) {
            $fromColumn = trim((string) ($row->analysis_result ?? ''));
            if ($fromColumn !== '') {
                return $fromColumn;
            }
        }

        $detalhes = trim((string) ($row->detalhes ?? ''));
        if ($detalhes === '') {
            return null;
        }

        $parsed = json_decode($detalhes, true);
        if (! is_array($parsed) || ! array_key_exists('analysisResult', $parsed) || $parsed['analysisResult'] === null) {
            return null;
        }

        return (string) $parsed['analysisResult'];
    }

    private function getTipoLegivel(string $tipo): string
    {
        return match (strtolower(trim($tipo))) {
            'refund_request', 'refund' => 'Solicitação de devolução (MED)',
            'fraud', 'fraude' => 'Suspeita de fraude',
            'operational_flaw', 'operational' => 'Falha operacional',
            'pix' => 'Infração Pix',
            default => ucfirst(str_replace('_', ' ', strtolower($tipo))),
        };
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function buildDetalhesAdicionais(string $detalhesJson): array
    {
        if (trim($detalhesJson) === '') {
            return [];
        }

        $parsed = json_decode($detalhesJson, true);
        if (! is_array($parsed)) {
            return [['label' => 'Informação', 'value' => $detalhesJson]];
        }

        $items = [];

        if (! empty($parsed['infractionId'])) {
            $items[] = [
                'label' => 'ID na adquirente (Treeal)',
                'value' => (string) $parsed['infractionId'],
            ];
        }

        if (! empty($parsed['status'])) {
            $items[] = [
                'label' => 'Situação na Treeal',
                'value' => $this->mapTreealInfractionStatus((string) $parsed['status']),
            ];
        }

        if (array_key_exists('analysisResult', $parsed)) {
            $result = $parsed['analysisResult'] ? (string) $parsed['analysisResult'] : null;
            $items[] = [
                'label' => 'Resultado da análise',
                'value' => $result
                    ? $this->mapAnalysisResultLegivel($result)
                    : 'Aguardando conclusão',
            ];

            if ($result) {
                $items[] = [
                    'label' => 'Efeito no seu saldo',
                    'value' => match (strtoupper(trim($result))) {
                        'AGREED' => 'Debitado — o Pix foi devolvido ao pagador',
                        'DISAGREED' => 'Liberado — permanece no saldo disponível',
                        default => 'Consulte o status do depósito relacionado',
                    },
                ];
            }
        }

        if (! empty($parsed['reportedBy'])) {
            $items[] = [
                'label' => 'Quem abriu a reclamação',
                'value' => $this->mapReportedByLegivel((string) $parsed['reportedBy']),
            ];
        }

        return $items;
    }

    private function mapTreealInfractionStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'OPEN' => 'Aberta',
            'WAITING_PSP' => 'Aguardando análise do participante',
            'ACKNOWLEDGED' => 'Recebida pela instituição',
            'WAITING_ADJUSTMENTS' => 'Aguardando ajustes',
            'DEFENDED' => 'Defesa registrada',
            'ANSWERED' => 'Respondida',
            'CLOSED' => 'Encerrada',
            'CANCELLED' => 'Cancelada',
            default => ucfirst(strtolower(str_replace('_', ' ', $status))),
        };
    }

    private function mapAnalysisResultLegivel(string $result): string
    {
        return match (strtoupper(trim($result))) {
            'AGREED' => 'Contestação aceita — pagador recebeu a devolução',
            'DISAGREED' => 'Contestação rejeitada — valor mantido com você',
            default => ucfirst(strtolower(str_replace('_', ' ', $result))),
        };
    }

    private function mapReportedByLegivel(string $reportedBy): string
    {
        return match (strtoupper(trim($reportedBy))) {
            'DEBITED_PARTICIPANT' => 'Titular da conta que pagou o Pix',
            'CREDITED_PARTICIPANT' => 'Titular da conta que recebeu o Pix',
            default => ucfirst(strtolower(str_replace('_', ' ', $reportedBy))),
        };
    }

    private function canPresentDefense(object $row): bool
    {
        $status = strtoupper((string) ($row->status ?? ''));

        if (! in_array($status, ['PENDENTE', 'EM_ANALISE'], true)) {
            return false;
        }

        if (! Schema::hasColumn('pix_infracoes', 'provider_infraction_id')) {
            return true;
        }

        return trim((string) ($row->provider_infraction_id ?? '')) !== '';
    }
}


