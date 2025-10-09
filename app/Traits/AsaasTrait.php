<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\App;
use App\Models\User;
use App\Helpers\Helper;
use App\Services\AsaasService;
use App\Traits\SplitTrait;
use App\Traits\IPManagementTrait;
use App\Helpers\TaxaFlexivelHelper;

trait AsaasTrait
{
    /**
     * Verifica se o IP está autorizado para operações de saque
     */
    public static function checkIPForWithdraw(User $user): array
    {
        $clientIP = IPManagementTrait::getClientIP();
        
        if (!IPManagementTrait::isIPAllowed($clientIP, $user)) {
            return [
                'success' => false,
                'message' => 'IP não autorizado para realizar saques',
                'client_ip' => $clientIP
            ];
        }
        
        return [
            'success' => true,
            'client_ip' => $clientIP
        ];
    }

    /**
     * Processa depósito via PIX (Cash-in)
     */
    public static function requestDepositAsaas($request)
    {
        try {
            $asaas = new AsaasService();
            
            // Gera external_id único para a transação
            $externalId = Str::uuid()->toString();
            
            $chargeData = [
                'amount' => $request->amount,
                'external_id' => $externalId,
                'description' => 'Depósito via PIX - ' . env('APP_NAME'),
                'customer_name' => $request->debtor_name ?? 'Cliente',
                'customer_email' => $request->email ?? 'cliente@email.com',
                'customer_phone' => $request->phone ?? '11999999999',
                'customer_document' => $request->debtor_document_number ?? '00000000000',
                'customer_external_id' => $externalId,
                'success_url' => $request->success_url ?? null
            ];

            $response = $asaas->createPixCharge($chargeData);

            if (!$response || !isset($response['id'])) {
                return [
                    'status' => 500,
                    'data' => ['message' => 'Erro ao criar cobrança PIX']
                ];
            }

            $setting = App::first();
            $user = $request->user;

            // Calcula taxas usando apenas configurações globais
            $taxaCalculada = TaxaFlexivelHelper::calcularTaxaDeposito($request->amount, $setting, $user);
            $deposito_liquido = $taxaCalculada['deposito_liquido'];
            $taxa_cash_in = $taxaCalculada['taxa_cash_in'];
            $descricao = $taxaCalculada['descricao'];

            $date = Carbon::now();

            $cashin = [
                "user_id" => $request->user->username,
                "externalreference" => $externalId,
                "amount" => $request->amount,
                "client_name" => $request->debtor_name,
                "client_document" => $request->debtor_document_number,
                "client_email" => $request->email,
                "date" => $date,
                "status" => 'WAITING_FOR_APPROVAL',
                "idTransaction" => $externalId,
                "deposito_liquido" => $deposito_liquido,
                "qrcode_pix" => $response['qr_code'] ?? $response['pixTransaction']['qrCode'] ?? '',
                "paymentcode" => $response['qr_code'] ?? $response['pixTransaction']['qrCode'] ?? '',
                "paymentCodeBase64" => $response['qr_code'] ?? $response['pixTransaction']['qrCode'] ?? '',
                "adquirente_ref" => 'asaas',
                "taxa_cash_in" => $taxa_cash_in,
                "taxa_pix_cash_in_adquirente" => 0,
                "taxa_pix_cash_in_valor_fixo" => 0,
                "client_telefone" => $request->phone,
                "executor_ordem" => 'asaas',
                "descricao_transacao" => $descricao,
                "callback" => $request->postback,
                "split_email" => $request->split_email ?? null,
                "split_percentage" => $request->split_percentage ?? null,
            ];

            Solicitacoes::create($cashin);

            // UTMfy integration
            if (!is_null($user->integracao_utmfy)) {
                $ip = $request->header('X-Forwarded-For') ?
                    $request->header('X-Forwarded-For') : ($request->header('CF-Connecting-IP') ?
                        $request->header('CF-Connecting-IP') :
                        $request->ip());

                $msg = "PIX Gerado " . env('APP_NAME');
                UtmfyTrait::gerarUTM('pix', 'waiting_payment', $cashin, $user->integracao_utmfy, $ip, $msg);
            }

            return [
                'status' => 200,
                'data' => [
                    'idTransaction' => $externalId,
                    'qrcode' => $response['qr_code'] ?? $response['pixTransaction']['qrCode'] ?? '',
                    'qr_code_image_url' => $response['qr_code_image_url'] ?? null
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no AsaasTrait::requestDepositAsaas: ' . $e->getMessage());
            return [
                'status' => 500,
                'data' => ['message' => 'Erro interno do servidor']
            ];
        }
    }

    /**
     * Processa saque via PIX (Cash-out)
     */
    public static function requestPaymentAsaas($request)
    {
        try {
            $request = $request->all();
            $user = User::where('id', $request->user()->id)->first();
            $setting = App::first();

            // Determinar se é saque via interface web ou API
            $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';

            // Verificar se deve usar taxa por fora para saques via API
            $taxaPorFora = $setting->taxa_por_fora_api ?? true;

            // Calcula taxas de saque usando o helper centralizado
            $taxaCalculada = \App\Helpers\TaxaSaqueHelper::calcularTaxaSaque((float)$request->amount, $setting, $user, $isInterfaceWeb, $taxaPorFora);
            $cashout_liquido = $taxaCalculada['saque_liquido'];
            $taxa_cash_out = $taxaCalculada['taxa_cash_out'];
            $descricao = $taxaCalculada['descricao'];
            $valor_total_descontar = $taxaCalculada['valor_total_descontar'] ?? $request->amount;

            // Verificar saldo considerando taxa por fora
            $saldo_necessario = $taxaPorFora ? $valor_total_descontar : $cashout_liquido;
            if ($user->saldo < $saldo_necessario) {
                return [
                    'status' => 401,
                    'data' => ['message' => "Saldo insuficiente. Necessário: R$ " . number_format($saldo_necessario, 2, ',', '.') . ", Disponível: R$ " . number_format($user->saldo, 2, ',', '.')]
                ];
            }

            $date = Carbon::now();

            // Se for web, verificar se é saque automático
            if ($request->baasPostbackUrl === 'web') {
                if ($request->has('saque_automatico') && $request->saque_automatico) {
                    // Processar saque automático diretamente via API
                    return self::processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
                } else {
                    // Processar como manual (criar solicitação para aprovação)
                    return self::generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
                }
            }

            $asaas = new AsaasService();
            $externalId = Str::uuid()->toString();

            // Prepara dados do PIX
            $pixKey = $request->pixKey;
            switch ($request->pixKeyType) {
                case 'cpf':
                case 'cnpj':
                case 'telefone':
                case 'phone':
                    $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                    break;
            }

            $transferData = [
                'amount' => $request->amount,
                'external_id' => $externalId,
                'pix_key' => $pixKey,
                'description' => 'Saque via PIX - ' . $request->user()->name,
                'schedule_date' => date('Y-m-d')
            ];

            $response = $asaas->makePixTransfer($transferData);

            if (!$response || !isset($response['id'])) {
                return [
                    'status' => 500,
                    'data' => [
                        'message' => 'Erro ao processar transferência via Asaas',
                        'asaas_error' => true,
                        'details' => $response['details'] ?? null,
                        'asaas_raw_response' => $response['raw_response'] ?? null
                    ]
                ];
            }

            // Criar registro de saque
            $cashout = [
                "user_id" => $request->user()->username,
                "externalreference" => $externalId,
                "amount" => $request->amount,
                "cash_out_liquido" => $cashout_liquido,
                "taxa_cash_out" => $taxa_cash_out,
                "pix" => $pixKey,
                "pixkey" => strtolower($request->pixKeyType),
                "beneficiaryname" => $request->user()->name,
                "beneficiarydocument" => $pixKey,
                "date" => $date,
                "status" => 'PENDING',
                "idTransaction" => $externalId,
                "end_to_end" => $externalId,
                "descricao_transacao" => $descricao,
                "executor_ordem" => 'asaas',
                "type" => "PIX",
                "callback" => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/'
            ];

            SolicitacoesCashOut::create($cashout);

            return [
                'status' => 200,
                'data' => [
                    'id' => $externalId,
                    'amount' => $request->amount,
                    'pixKey' => $pixKey,
                    'pixKeyType' => $request->pixKeyType,
                    'withdrawStatusId' => 'PendingProcessing',
                    'createdAt' => $date->toISOString(),
                    'updatedAt' => $date->toISOString()
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no AsaasTrait::requestPaymentAsaas: ' . $e->getMessage());
            return [
                'status' => 500,
                'data' => ['message' => 'Erro interno do servidor']
            ];
        }
    }

    /**
     * Processa saque automático via Asaas
     */
    private static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        try {
            $asaas = new AsaasService();
            $externalId = Str::uuid()->toString();

            // Prepara dados do PIX
            $pixKey = $request->pixKey;
            switch ($request->pixKeyType) {
                case 'cpf':
                case 'cnpj':
                case 'telefone':
                case 'phone':
                    $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                    break;
            }

            $transferData = [
                'amount' => $request->amount,
                'external_id' => $externalId,
                'pix_key' => $pixKey,
                'description' => 'Saque automático - ' . $request->user()->name,
                'schedule_date' => date('Y-m-d')
            ];

            $response = $asaas->makePixTransfer($transferData);

            if (!$response || !isset($response['id'])) {
                return [
                    'status' => 500,
                    'data' => [
                        'message' => 'Erro ao processar saque automático via Asaas',
                        'asaas_error' => true,
                        'details' => $response['details'] ?? null,
                        'asaas_raw_response' => $response['raw_response'] ?? null
                    ]
                ];
            }

            // Criar registro de saque
            $cashout = [
                "user_id" => $request->user()->username,
                "externalreference" => $externalId,
                "amount" => $request->amount,
                "cash_out_liquido" => $cashout_liquido,
                "taxa_cash_out" => $taxa_cash_out,
                "pix" => $pixKey,
                "pixkey" => strtolower($request->pixKeyType),
                "beneficiaryname" => $request->user()->name,
                "beneficiarydocument" => $pixKey,
                "date" => $date,
                "status" => 'PENDING',
                "idTransaction" => $externalId,
                "end_to_end" => $externalId,
                "descricao_transacao" => $descricao,
                "executor_ordem" => 'asaas',
                "type" => "PIX",
                "callback" => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/'
            ];

            SolicitacoesCashOut::create($cashout);

            return [
                'status' => 200,
                'data' => [
                    'id' => $externalId,
                    'amount' => $request->amount,
                    'pixKey' => $pixKey,
                    'pixKeyType' => $request->pixKeyType,
                    'withdrawStatusId' => 'PendingProcessing',
                    'createdAt' => $date->toISOString(),
                    'updatedAt' => $date->toISOString()
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no AsaasTrait::processarSaqueAutomatico: ' . $e->getMessage());
            return [
                'status' => 500,
                'data' => ['message' => 'Erro interno do servidor']
            ];
        }
    }

    /**
     * Gera transação manual para aprovação
     */
    private static function generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        try {
            $externalId = Str::uuid()->toString();

            // Prepara dados do PIX
            $pixKey = $request->pixKey;
            switch ($request->pixKeyType) {
                case 'cpf':
                case 'cnpj':
                case 'telefone':
                case 'phone':
                    $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                    break;
            }

            // Criar registro de saque manual
            $cashout = [
                "user_id" => $request->user()->username,
                "externalreference" => $externalId,
                "amount" => $request->amount,
                "cash_out_liquido" => $cashout_liquido,
                "taxa_cash_out" => $taxa_cash_out,
                "pix" => $pixKey,
                "pixkey" => strtolower($request->pixKeyType),
                "beneficiaryname" => $request->user()->name,
                "beneficiarydocument" => $pixKey,
                "date" => $date,
                "status" => 'PENDING_APPROVAL',
                "idTransaction" => $externalId,
                "end_to_end" => $externalId,
                "descricao_transacao" => $descricao,
                "executor_ordem" => 'asaas',
                "type" => "PIX",
                "callback" => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/'
            ];

            SolicitacoesCashOut::create($cashout);

            return [
                'status' => 200,
                'data' => [
                    'id' => $externalId,
                    'amount' => $request->amount,
                    'pixKey' => $pixKey,
                    'pixKeyType' => $request->pixKeyType,
                    'withdrawStatusId' => 'PendingApproval',
                    'createdAt' => $date->toISOString(),
                    'updatedAt' => $date->toISOString(),
                    'message' => 'Solicitação de saque criada e aguardando aprovação'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no AsaasTrait::generateTransactionPaymentManual: ' . $e->getMessage());
            return [
                'status' => 500,
                'data' => ['message' => 'Erro interno do servidor']
            ];
        }
    }

    /**
     * Libera saque manual via Asaas
     */
    public static function liberarSaqueManual($id)
    {
        try {
            $cashout = SolicitacoesCashOut::where('id', $id)->with('user')->first();
            
            if (!$cashout) {
                return back()->with('error', 'Solicitação de saque não encontrada.');
            }

            $asaas = new AsaasService();
            $externalId = Str::uuid()->toString();

            if ($cashout->type == "CRYPTO") {
                // Para crypto, mantém o comportamento manual
                $pixcashout = [
                    "externalreference" => $externalId,
                    "idTransaction" => $externalId,
                    "end_to_end" => $externalId,
                    "descricao_transacao" => "LIBERADOADMIN"
                ];

                $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
                return back()->with('success', 'Pedido de saque enviado com sucesso!');
            }

            // Para PIX, processa via API
            $transferData = [
                'amount' => $cashout->cash_out_liquido,
                'external_id' => $externalId,
                'pix_key' => $cashout->pix,
                'description' => 'Saque liberado pelo admin - ' . $cashout->beneficiaryname,
                'schedule_date' => date('Y-m-d')
            ];

            $response = $asaas->makePixTransfer($transferData);

            if (!$response || !isset($response['id'])) {
                return back()->with('error', 'Erro ao processar transferência via Asaas.');
            }

            $pixcashout = [
                "externalreference" => $externalId,
                "idTransaction" => $externalId,
                "end_to_end" => $externalId,
                "descricao_transacao" => "LIBERADOADMIN"
            ];

            $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
            return back()->with('success', 'Pedido de saque enviado com sucesso!');

        } catch (\Exception $e) {
            Log::error('Erro no AsaasTrait::liberarSaqueManual: ' . $e->getMessage());
            return back()->with('error', 'Erro interno do servidor.');
        }
    }

    /**
     * Cria um registro de saque com retry em caso de ID duplicado
     */
    private static function createCashOutWithRetry($data, $maxRetries = 3)
    {
        $attempt = 0;
        $originalId = $data['idTransaction'];
        
        while ($attempt < $maxRetries) {
            try {
                $solicitacao = SolicitacoesCashOut::create($data);
                
                if ($attempt > 0) {
                    Log::info('AsaasTrait::createCashOutWithRetry - Registro criado após retry:', [
                        'id_original' => $originalId,
                        'id_usado' => $data['idTransaction'],
                        'tentativa' => $attempt + 1,
                        'registro_id' => $solicitacao->id
                    ]);
                }
                
                return $solicitacao;
                
            } catch (\Illuminate\Database\QueryException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                    strpos($e->getMessage(), '1062') !== false) {
                    
                    $attempt++;
                    
                    if ($attempt >= $maxRetries) {
                        Log::error('AsaasTrait::createCashOutWithRetry - Falha após múltiplas tentativas:', [
                            'id_original' => $originalId,
                            'ultimo_id_tentado' => $data['idTransaction'],
                            'tentativas' => $attempt,
                            'erro' => $e->getMessage()
                        ]);
                        throw $e;
                    }
                    
                    $newId = Str::uuid()->toString();
                    $data['idTransaction'] = $newId;
                    
                    Log::warning('AsaasTrait::createCashOutWithRetry - ID duplicado detectado, tentando com novo ID:', [
                        'id_original' => $originalId,
                        'id_novo' => $newId,
                        'tentativa' => $attempt + 1
                    ]);
                    
                    continue;
                }
                
                throw $e;
            }
        }
        
        throw new \Exception('Falha ao criar registro de saque após ' . $maxRetries . ' tentativas');
    }

    /**
     * Garante que o idTransaction seja único, gerando um novo se necessário
     */
    private static function ensureUniqueTransactionId($requestedId, $userId)
    {
        if (empty($requestedId)) {
            return Str::uuid()->toString();
        }

        $existing = SolicitacoesCashOut::where('idTransaction', $requestedId)->first();
        
        if (!$existing) {
            return $requestedId;
        }

        if (in_array($existing->status, ['CANCELLED', 'FAILED', 'REJECTED'])) {
            $newId = $requestedId . '_' . time();
            Log::warning('AsaasTrait::ensureUniqueTransactionId - ID duplicado encontrado (status: ' . $existing->status . '), gerando novo:', [
                'id_original' => $requestedId,
                'id_novo' => $newId,
                'user_id' => $userId,
                'registro_existente_id' => $existing->id,
                'status_existente' => $existing->status
            ]);
            return $newId;
        }

        $newId = Str::uuid()->toString();
        Log::warning('AsaasTrait::ensureUniqueTransactionId - ID duplicado em uso ativo, gerando UUID novo:', [
            'id_original' => $requestedId,
            'id_novo' => $newId,
            'user_id' => $userId,
            'registro_existente_id' => $existing->id,
            'status_existente' => $existing->status
        ]);
        return $newId;
    }
}
