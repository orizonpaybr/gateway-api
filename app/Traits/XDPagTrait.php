<?php

namespace App\Traits;

use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\App;
use App\Services\XDPagService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait XDPagTrait
{
    /**
     * Solicita depósito PIX via XDPag
     */
    public static function requestDepositXDPag($request)
    {
        Log::info('XDPagTrait::requestDepositXDPag - INÍCIO', [
            'checkout_id' => $request->checkout_id ?? null,
            'metodo' => $request->metodo ?? null,
            'amount' => $request->amount,
            'all_data' => $request->all(),
            'has_checkout_id' => $request->has('checkout_id')
        ]);
        
        try {
            $xdpagConfig = \App\Models\XDPag::first();
            if (!$xdpagConfig || !$xdpagConfig->status) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "XDPag não configurado ou inativo."
                    ]
                ];
            }

            // Usar o usuário já autenticado pelo middleware ou dados do checkout
            $user = $request->user();
            
            // Se não há usuário autenticado, buscar dados do checkout
            if (!$user && $request->has('checkout_id')) {
                $checkout = \App\Models\CheckoutBuild::where('id', $request->checkout_id)->first();
                if ($checkout) {
                    $user = \App\Models\User::where('id', $checkout->user_id)->first();
                    Log::info('XDPagTrait: Usuário obtido via checkout', [
                        'checkout_id' => $request->checkout_id,
                        'user_id' => $user ? $user->id : 'não encontrado'
                    ]);
                }
            }
            
            if (!$user) {
                return [
                    "status" => 404,
                    "data" => [
                        "status" => "error",
                        "message" => "Usuário não encontrado."
                    ]
                ];
            }

            // Usar valor_total do checkout se amount não estiver disponível
            $valor = (float) ($request->amount ?? $request->valor_total);
            $setting = \App\Models\App::first();

            Log::info('=== XDPAGTRAIT REQUEST DEPOSIT INICIADO ===');
            Log::info('XDPagTrait::requestDepositXDPag - Dados da requisição:', [
                'user_id' => $user->id,
                'username' => $user->username,
                'amount' => $valor,
                'debtor_name' => $request->debtor_name ?? $request->name,
                'debtor_document_number' => $request->debtor_document_number ?? $request->cpf,
                'email' => $request->email,
                'phone' => $request->phone ?? $request->telefone,
                'checkout_id' => $request->checkout_id
            ]);

            // Calcula taxas usando o sistema flexível (com prioridade do usuário)
            $taxaCalculada = \App\Helpers\TaxaFlexivelHelper::calcularTaxaDeposito($valor, $setting, $user);
            $valor_liquido = $taxaCalculada['deposito_liquido'];
            $taxa_cash_in = $taxaCalculada['taxa_cash_in'];
            $descricao_taxa = $taxaCalculada['descricao'];

            Log::info('XDPagTrait::requestDepositXDPag - Cálculo de taxas:', [
                'amount_original' => $valor,
                'taxa_cash_in' => $taxa_cash_in,
                'deposito_liquido' => $valor_liquido,
                'descricao' => $descricao_taxa
            ]);

            $date = Carbon::now();
            $descricao = "Depósito PIX via XDPag - R$ " . number_format($valor, 2, ',', '.');

            $xdpag = new XDPagService();
            // Usar ID do cassino se fornecido, senão gerar um novo
            $externalId = $request->idTransaction ?? Str::uuid()->toString();
            
            Log::info('XDPagTrait::requestDepositXDPag - ID da transação:', [
                'id_from_cassino' => $request->idTransaction,
                'external_id_used' => $externalId,
                'is_cassino_id' => !is_null($request->idTransaction)
            ]);

            // Validar CPF/CNPJ antes de enviar para o XDPag
            $documentNumber = $request->debtor_document_number ?? $request->cpf ?? $user->cpf_cnpj ?? null;
            
            // Se não houver documento, gerar um CPF válido para teste
            if (!$documentNumber || $documentNumber === '00000000000') {
                $documentNumber = \App\Helpers\Helper::generateValidCpf();
                Log::info('XDPagTrait: Gerando CPF válido para teste', ['cpf_gerado' => $documentNumber]);
            } else {
                $cleanDocument = preg_replace('/\D/', '', $documentNumber);
                
                // Verificar se é um CPF (11 dígitos) - válido ou inválido
                if (strlen($cleanDocument) === 11) {
                    if (!\App\Helpers\Helper::validarCPF($documentNumber)) {
                        return [
                            "status" => 400,
                            "data" => [
                                "status" => "error",
                                "message" => "CPF inválido. Por favor, verifique o número do documento."
                            ]
                        ];
                    }
                }
                
                // Verificar se é um CNPJ (14 dígitos)
                if (strlen($cleanDocument) === 14) {
                    if (!\App\Helpers\Helper::validarCNPJ($documentNumber)) {
                        return [
                            "status" => 400,
                            "data" => [
                                "status" => "error",
                                "message" => "CNPJ inválido. Por favor, verifique o número do documento."
                            ]
                        ];
                    }
                }
            }

            // URL do callback
            $callbackUrl = env('APP_URL') . '/api/xdpag/callback/deposit';

            $qrCodeData = [
                'amount' => $valor,
                'external_id' => $externalId,
                'postback_url' => $callbackUrl,
                'description' => $descricao,
                'debtor_name' => $request->debtor_name ?? $request->name ?? $user->name,
                'debtor_document_number' => $documentNumber,
                'email' => $request->email ?? $user->email,
                'phone' => $request->phone ?? $request->telefone ?? $user->telefone ?? '11999999999'
            ];

            Log::info('XDPagTrait::requestDepositXDPag - Dados do QR Code:', $qrCodeData);

            $response = $xdpag->generateQrCode($qrCodeData);

            if (!$response || isset($response['error'])) {
                Log::error('XDPagTrait::requestDepositXDPag - Erro ao gerar QR Code', ['response' => $response]);
                
                $errorMessage = 'Erro ao gerar QR Code';
                if (isset($response['message'])) {
                    $errorMessage = $response['message'];
                }

                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => $errorMessage
                    ]
                ];
            }

            Log::info('XDPagTrait::requestDepositXDPag - QR Code gerado com sucesso:', $response);

            // Criar registro de solicitação
            $solicitacao = Solicitacoes::create([
                'user_id' => $user->user_id,
                'externalreference' => $externalId,
                'amount' => $valor,
                'deposito_liquido' => $valor_liquido,
                'taxa_cash_in' => $taxa_cash_in,
                'taxa_pix_cash_in_adquirente' => 0,
                'taxa_pix_cash_in_valor_fixo' => 0,
                'client_name' => $request->debtor_name ?? $request->name ?? $user->name,
                'client_document' => $documentNumber,
                'client_email' => $request->email ?? $user->email,
                'client_telefone' => $request->phone ?? $request->telefone ?? $user->telefone ?? '11999999999',
                'executor_ordem' => 'xdpag',
                'status' => 'WAITING_FOR_APPROVAL',
                'descricao_transacao' => $descricao_taxa,
                'idTransaction' => $externalId,
                'qrcode_pix' => $response['brcode'] ?? null,
                'paymentcode' => $response['brcode'] ?? null,
                'paymentCodeBase64' => $response['brcode'] ?? null,
                'method' => 'PIX',
                'adquirente_ref' => 'xdpag',
                'callback' => $request->postback ?? $user->webhook_url ?? $callbackUrl,
                'split_email' => $request->split_email ?? null,
                'split_percentage' => $request->split_percentage ?? null,
                'date' => $date,
                'created_at' => $date,
                'updated_at' => $date
            ]);

            Log::info('XDPagTrait::requestDepositXDPag - Registro de solicitação criado:', [
                'solicitacao_id' => $solicitacao->id,
                'externalreference' => $solicitacao->externalreference,
                'amount' => $solicitacao->amount,
                'deposito_liquido' => $solicitacao->deposito_liquido,
                'taxa_cash_in' => $solicitacao->taxa_cash_in
            ]);

            Log::info('=== XDPAGTRAIT REQUEST DEPOSIT FINALIZADO ===');

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Cobrança PIX criada com sucesso",
                    "idTransaction" => $externalId,
                    "qrcode" => $response['brcode'] ?? null,
                    "qr_code_image_url" => $response['qr_code_image_url'] ?? null,
                    "charge" => [
                        "id" => $externalId,
                        "value" => $valor,
                        "qrCode" => $response['qr_code_image_url'] ?? null,
                        "brCode" => $response['brcode'] ?? null,
                        "pixKey" => null,
                        "expiresAt" => $response['expires_at'] ?? null
                    ],
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no XDPagTrait::requestDepositXDPag: ' . $e->getMessage());
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro interno do servidor."
                ]
            ];
        }
    }

    /**
     * Solicita pagamento PIX via XDPag
     */
    public static function requestPaymentXDPag($request)
    {
        try {
            $data = $request->all();
            $user = User::where('id', $request->user()->id)->first();
            $setting = App::first();

            Log::info('=== XDPAGTRAIT REQUEST PAYMENT INICIADO ===');
            Log::info('XDPagTrait::requestPaymentXDPag - Dados da requisição:', [
                'user_id' => $user->id,
                'username' => $user->username,
                'amount' => $request->amount,
                'pix_key' => $request->pixKey,
                'pix_key_type' => $request->pixKeyType,
                'baasPostbackUrl' => $request->baasPostbackUrl,
                'is_interface_web' => $request->input('baasPostbackUrl') === 'web'
            ]);

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

            Log::info('XDPagTrait::requestPaymentXDPag - Cálculo de taxas:', [
                'amount_original' => $request->amount,
                'taxa_cash_out' => $taxa_cash_out,
                'cashout_liquido' => $cashout_liquido,
                'descricao' => $descricao,
                'user_saldo' => $user->saldo,
                'is_interface_web' => $isInterfaceWeb
            ]);

            // Verificar saldo considerando taxa por fora
            $saldo_necessario = $valor_total_descontar; // Sempre usar valor total a descontar

            if ($user->saldo < $saldo_necessario) {
                Log::warning('XDPagTrait::requestPaymentXDPag - Saldo insuficiente:', [
                    'user_id' => $user->user_id,
                    'saldo_atual' => $user->saldo,
                    'saldo_necessario' => $saldo_necessario,
                    'valor_saque' => $request->amount,
                    'taxa_cash_out' => $taxa_cash_out
                ]);
                return [
                    'status' => 400,
                    'data' => [
                        'status' => false,
                        'message' => 'Saldo insuficiente para realizar o saque',
                        'saldo_atual' => $user->saldo,
                        'saldo_necessario' => $saldo_necessario
                    ]
                ];
            }

            $date = Carbon::now();

            // Se for web, verificar se é saque automático
            if ($request->baasPostbackUrl === 'web') {
                Log::info('XDPagTrait::requestPaymentXDPag - Interface web detectada:', [
                    'saque_automatico' => $request->has('saque_automatico') ? $request->saque_automatico : false,
                    'has_saque_automatico' => $request->has('saque_automatico')
                ]);
                
                if ($request->has('saque_automatico') && $request->saque_automatico) {
                    Log::info('XDPagTrait::requestPaymentXDPag - Processando saque automático');
                    // Processar saque automático diretamente via API
                    return self::processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
                } else {
                    Log::info('XDPagTrait::requestPaymentXDPag - Processando saque manual');
                    // Processar como manual (criar solicitação para aprovação)
                    return self::generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
                }
            }

            Log::info('XDPagTrait::requestPaymentXDPag - Processando via API (não web)');
            
            $xdpag = new XDPagService();
            // Garantir ID único para evitar conflitos de chave duplicada
            $externalId = self::ensureUniqueTransactionId($request->idTransaction, $user->user_id);
            
            Log::info('XDPagTrait::requestPaymentXDPag - ID da transação:', [
                'id_from_cassino' => $request->idTransaction,
                'external_id_used' => $externalId,
                'is_cassino_id' => !is_null($request->idTransaction),
                'id_foi_modificado' => ($request->idTransaction != $externalId)
            ]);

            // Normalizar dados da chave PIX
            $normalizedData = self::normalizePixKeyData(
                $request->pixKey,
                $request->pixKeyType,
                $request->beneficiaryDocument ?? null,
                $user->cpf ?? null
            );

            Log::info('XDPagTrait::requestPaymentXDPag - Dados normalizados:', $normalizedData);

            // Prepara dados do PIX
            $pixKey = $normalizedData['pix_key'];
            
            // Limpar caracteres especiais para CPF/CNPJ/Telefone
            if (in_array(strtolower($normalizedData['pix_key_type']), ['cpf', 'cnpj', 'telefone', 'phone'])) {
                $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
            }

            // Mapear tipos de chave PIX para o formato da API XDPag
            $pixKeyTypeMapping = [
                'cpf' => 'CPF',
                'cnpj' => 'CNPJ',
                'email' => 'EMAIL',
                'telefone' => 'PHONE',
                'phone' => 'PHONE',
                'random' => 'RANDOM',
                'crypto' => 'CRYPTO'
            ];

            $pixKeyType = $pixKeyTypeMapping[strtolower($normalizedData['pix_key_type'])] ?? 'CPF';

            // URL do callback
            $callbackUrl = env('APP_URL') . '/api/xdpag/callback/withdraw';

            $paymentData = [
                'amount' => $cashout_liquido,
                'external_id' => $externalId,
                'postback_url' => $callbackUrl,
                'description' => $descricao,
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'beneficiary_name' => $request->beneficiaryName ?? $user->username,
                'beneficiary_document' => $normalizedData['document']
            ];

            Log::info('XDPagTrait::requestPaymentXDPag - Dados do pagamento:', $paymentData);

            $response = $xdpag->makePayment($paymentData);

            Log::info('XDPagTrait::requestPaymentXDPag - Resposta completa da API:', [
                'response' => $response,
                'has_id' => isset($response['data']['id']),
                'has_status' => isset($response['data']['status']),
                'has_error' => isset($response['error']),
                'response_type' => gettype($response)
            ]);

            if ($response && isset($response['data']['id']) && isset($response['data']['status'])) {
                Log::info('XDPagTrait::requestPaymentXDPag - Pagamento criado com sucesso:', $response);

                // Criar solicitação de saque com proteção contra ID duplicado
                $solicitacao = self::createCashOutWithRetry([
                    'user_id' => $user->user_id,
                    'externalreference' => $response['data']['id'] ?? $externalId,
                    'amount' => $request->amount,
                    'beneficiaryname' => $request->beneficiaryName ?? $user->username,
                    'beneficiarydocument' => $normalizedData['document'],
                    'pix' => $normalizedData['pix_key'],
                    'pixkey' => $normalizedData['pix_key_type'],
                    'date' => $date,
                    'status' => 'PENDING',
                    'type' => 'PIX',
                    'idTransaction' => $externalId,
                    'taxa_cash_out' => $taxa_cash_out,
                    'cash_out_liquido' => $cashout_liquido,
                    'end_to_end' => $response['data']['id'] ?? null,
                    'descricao_transacao' => 'API',
                    'executor_ordem' => 'xdpag',
                    'descricao_externa' => $request->description ?? 'Saque via PIX',
                    'callback' => $request->baasPostbackUrl ?? 'web'
                ]);

                Log::info('XDPagTrait::requestPaymentXDPag - Callback salvo e IDs', [
                    'callback' => $solicitacao->callback,
                    'idTransaction' => $solicitacao->idTransaction,
                    'externalreference' => $solicitacao->externalreference,
                    'end_to_end' => $solicitacao->end_to_end,
                ]);

                // Descontar saldo do usuário
                if ($taxaPorFora) {
                    // Taxa por fora: descontar valor total (saque + taxa)
                    \App\Helpers\Helper::decrementAmount($user, $valor_total_descontar, 'saldo');
                } else {
                    // Taxa por dentro: descontar apenas o valor do saque
                    \App\Helpers\Helper::decrementAmount($user, $request->amount, 'saldo');
                }

                \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);

                // Log da operação
                \App\Helpers\BalanceLogHelper::logSaqueOperation(
                    'SAQUE_REQUEST',
                    $user,
                    $request->amount,
                    [
                        'adquirente' => 'XDPAG',
                        'valor_bruto' => $request->amount,
                        'valor_liquido' => $cashout_liquido,
                        'taxa_cash_out' => $taxa_cash_out,
                        'taxa_por_fora' => $taxaPorFora,
                        'external_id' => $externalId,
                        'operacao' => 'xdpag_request_payment'
                    ]
                );

                Log::info('XDPagTrait::requestPaymentXDPag - Saque processado com sucesso:', [
                    'user_id' => $user->user_id,
                    'external_id' => $externalId,
                    'amount' => $request->amount,
                    'cashout_liquido' => $cashout_liquido,
                    'taxa_cash_out' => $taxa_cash_out
                ]);

                return [
                    'status' => 200,
                    'data' => [
                        'status' => true,
                        'message' => 'Saque processado com sucesso',
                        'transaction_id' => $externalId,
                        'amount' => $request->amount,
                        'cashout_liquido' => $cashout_liquido,
                        'taxa_cash_out' => $taxa_cash_out,
                        'status' => 'PENDING'
                    ]
                ];

            } else {
                Log::error('XDPagTrait::requestPaymentXDPag - Erro ao criar pagamento:', $response);
                
                $errorMessage = 'Erro ao processar saque';
                if (isset($response['message'])) {
                    $errorMessage = $response['message'];
                }

                return [
                    'status' => 500,
                    'data' => [
                        'status' => false,
                        'message' => $errorMessage,
                        'details' => $response
                    ]
                ];
            }

        } catch (\Exception $e) {
            Log::error('XDPagTrait::requestPaymentXDPag - Exceção:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 500,
                'data' => [
                    'status' => false,
                    'message' => 'Erro interno do servidor',
                    'error' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Processa saque automático via XDPag
     */
    private static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        try {
            Log::info('XDPagTrait::processarSaqueAutomatico - Iniciando saque automático');

            $xdpag = new XDPagService();
            // Garantir ID único para evitar conflitos de chave duplicada
            $externalId = self::ensureUniqueTransactionId($request->idTransaction, $user->user_id);
            
            Log::info('XDPagTrait::processarSaqueAutomatico - ID da transação:', [
                'id_from_cassino' => $request->idTransaction,
                'external_id_used' => $externalId,
                'is_cassino_id' => !is_null($request->idTransaction),
                'id_foi_modificado' => ($request->idTransaction != $externalId)
            ]);

            // Normalizar dados da chave PIX
            $normalizedData = self::normalizePixKeyData(
                $request->pixKey,
                $request->pixKeyType,
                $request->beneficiaryDocument ?? null,
                $user->cpf ?? null
            );

            Log::info('XDPagTrait::processarSaqueAutomatico - Dados normalizados:', $normalizedData);

            // Prepara dados do PIX
            $pixKey = $normalizedData['pix_key'];
            
            // Limpar caracteres especiais para CPF/CNPJ/Telefone
            if (in_array(strtolower($normalizedData['pix_key_type']), ['cpf', 'cnpj', 'telefone', 'phone'])) {
                $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
            }

            $pixKeyTypeMapping = [
                'cpf' => 'CPF',
                'cnpj' => 'CNPJ',
                'email' => 'EMAIL',
                'telefone' => 'PHONE',
                'phone' => 'PHONE',
                'random' => 'RANDOM',
                'crypto' => 'CRYPTO'
            ];

            $pixKeyType = $pixKeyTypeMapping[strtolower($normalizedData['pix_key_type'])] ?? 'CPF';

            // URL do callback
            $callbackUrl = env('APP_URL') . '/api/xdpag/callback/withdraw';

            $paymentData = [
                'amount' => $cashout_liquido,
                'external_id' => $externalId,
                'postback_url' => $callbackUrl,
                'description' => $descricao,
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'beneficiary_name' => $request->beneficiaryName ?? $user->username,
                'beneficiary_document' => $normalizedData['document']
            ];

            Log::info('XDPagTrait::processarSaqueAutomatico - Dados do pagamento:', $paymentData);

            $response = $xdpag->makePayment($paymentData);

            if ($response && isset($response['data']['id']) && isset($response['data']['status'])) {
                Log::info('XDPagTrait::processarSaqueAutomatico - Pagamento criado com sucesso:', $response);

                // Criar solicitação de saque com proteção contra ID duplicado
                $solicitacao = self::createCashOutWithRetry([
                    'user_id' => $user->user_id,
                    'externalreference' => $response['data']['id'] ?? $externalId,
                    'amount' => $request->amount,
                    'beneficiaryname' => $request->beneficiaryName ?? $user->username,
                    'beneficiarydocument' => $normalizedData['document'],
                    'pix' => $normalizedData['pix_key'],
                    'pixkey' => $normalizedData['pix_key_type'],
                    'date' => $date,
                    'status' => 'PENDING',
                    'type' => 'PIX',
                    'idTransaction' => $externalId,
                    'taxa_cash_out' => $taxa_cash_out,
                    'cash_out_liquido' => $cashout_liquido,
                    'end_to_end' => $response['data']['id'] ?? null,
                    'descricao_transacao' => 'AUTOMATICO',
                    'executor_ordem' => 'xdpag',
                    'descricao_externa' => $request->description ?? 'Saque via PIX',
                    'callback' => 'web'
                ]);

                // Descontar saldo do usuário
                $setting = App::first();
                $taxaPorFora = $setting->taxa_por_fora_api ?? true;
                $valor_total_descontar = $taxaPorFora ? ($request->amount + $taxa_cash_out) : $request->amount;

                if ($taxaPorFora) {
                    \App\Helpers\Helper::decrementAmount($user, $valor_total_descontar, 'saldo');
                } else {
                    \App\Helpers\Helper::decrementAmount($user, $request->amount, 'saldo');
                }

                \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);

                // Log da operação
                \App\Helpers\BalanceLogHelper::logSaqueOperation(
                    'SAQUE_AUTOMATICO',
                    $user,
                    $request->amount,
                    [
                        'adquirente' => 'XDPAG',
                        'valor_bruto' => $request->amount,
                        'valor_liquido' => $cashout_liquido,
                        'taxa_cash_out' => $taxa_cash_out,
                        'taxa_por_fora' => $taxaPorFora,
                        'external_id' => $externalId,
                        'operacao' => 'xdpag_automatic_payment'
                    ]
                );

                Log::info('XDPagTrait::processarSaqueAutomatico - Saque automático processado com sucesso:', [
                    'user_id' => $user->user_id,
                    'external_id' => $externalId,
                    'amount' => $request->amount,
                    'cashout_liquido' => $cashout_liquido,
                    'taxa_cash_out' => $taxa_cash_out,
                    'descricao_transacao' => 'AUTOMATICO',
                    'observacao' => 'Saque processado automaticamente via API - NÃO requer aprovação manual'
                ]);

                return [
                    'status' => 200,
                    'data' => [
                        'status' => true,
                        'message' => 'Saque automático processado com sucesso',
                        'transaction_id' => $externalId,
                        'amount' => $request->amount,
                        'cashout_liquido' => $cashout_liquido,
                        'taxa_cash_out' => $taxa_cash_out,
                        'status' => 'PENDING'
                    ]
                ];

            } else {
                Log::error('XDPagTrait::processarSaqueAutomatico - Erro ao criar pagamento:', $response);
                
                $errorMessage = 'Erro ao processar saque automático';
                if (isset($response['message'])) {
                    $errorMessage = $response['message'];
                }

                return [
                    'status' => 500,
                    'data' => [
                        'status' => false,
                        'message' => $errorMessage,
                        'details' => $response
                    ]
                ];
            }

        } catch (\Exception $e) {
            Log::error('XDPagTrait::processarSaqueAutomatico - Exceção:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 500,
                'data' => [
                    'status' => false,
                    'message' => 'Erro interno do servidor',
                    'error' => $e->getMessage()
                ]
            ];
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
                    Log::info('XDPagTrait::createCashOutWithRetry - Registro criado após retry:', [
                        'id_original' => $originalId,
                        'id_usado' => $data['idTransaction'],
                        'tentativa' => $attempt + 1,
                        'registro_id' => $solicitacao->id
                    ]);
                }
                
                return $solicitacao;
                
            } catch (\Illuminate\Database\QueryException $e) {
                // Verificar se é erro de chave duplicada
                if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                    strpos($e->getMessage(), '1062') !== false) {
                    
                    $attempt++;
                    
                    if ($attempt >= $maxRetries) {
                        Log::error('XDPagTrait::createCashOutWithRetry - Falha após múltiplas tentativas:', [
                            'id_original' => $originalId,
                            'ultimo_id_tentado' => $data['idTransaction'],
                            'tentativas' => $attempt,
                            'erro' => $e->getMessage()
                        ]);
                        throw $e;
                    }
                    
                    // Gerar novo ID único e tentar novamente
                    $newId = Str::uuid()->toString();
                    $data['idTransaction'] = $newId;
                    
                    Log::warning('XDPagTrait::createCashOutWithRetry - ID duplicado detectado, tentando com novo ID:', [
                        'id_original' => $originalId,
                        'id_novo' => $newId,
                        'tentativa' => $attempt + 1
                    ]);
                    
                    // Continua o loop para tentar novamente
                    continue;
                }
                
                // Se não for erro de chave duplicada, relançar exceção
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

        // Verificar se já existe um registro com esse ID
        $existing = SolicitacoesCashOut::where('idTransaction', $requestedId)->first();
        
        if (!$existing) {
            // ID não existe, pode usar
            return $requestedId;
        }

        // ID já existe - verificar status
        if (in_array($existing->status, ['CANCELLED', 'FAILED', 'REJECTED'])) {
            // Registro antigo cancelado/falho - gerar novo ID único
            $newId = $requestedId . '_' . time();
            Log::warning('XDPagTrait::ensureUniqueTransactionId - ID duplicado encontrado (status: ' . $existing->status . '), gerando novo:', [
                'id_original' => $requestedId,
                'id_novo' => $newId,
                'user_id' => $userId,
                'registro_existente_id' => $existing->id,
                'status_existente' => $existing->status
            ]);
            return $newId;
        }

        // ID já existe e está ativo - gerar completamente novo
        $newId = Str::uuid()->toString();
        Log::warning('XDPagTrait::ensureUniqueTransactionId - ID duplicado em uso ativo, gerando UUID novo:', [
            'id_original' => $requestedId,
            'id_novo' => $newId,
            'user_id' => $userId,
            'registro_existente_id' => $existing->id,
            'status_existente' => $existing->status
        ]);
        return $newId;
    }

    /**
     * Normaliza dados da chave PIX (detecta UUID e ajusta tipo automaticamente)
     */
    private static function normalizePixKeyData($pixKey, $pixKeyType, $beneficiaryDocument = null, $userCpf = null)
    {
        // Detectar se é UUID (chave aleatória)
        $isUUID = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $pixKey);
        
        // Se for UUID, corrigir o tipo automaticamente
        if ($isUUID) {
            $pixKeyType = 'random';
            Log::info('XDPagTrait::normalizePixKeyData - Chave UUID detectada, tipo ajustado para RANDOM', [
                'pix_key' => $pixKey,
                'tipo_original' => $pixKeyType,
                'tipo_corrigido' => 'random'
            ]);
        }
        
        // CPF padrão se não houver CPF cadastrado
        $defaultCpf = '25211401042';
        $document = $beneficiaryDocument ?? $userCpf ?? $defaultCpf;
        
        // Se o documento for inválido (todos zeros), usar CPF padrão
        if (in_array($document, ['00000000000', '00000000000000', null, ''])) {
            $document = $defaultCpf;
            Log::info('XDPagTrait::normalizePixKeyData - CPF inválido ou vazio, usando CPF padrão', [
                'documento_original' => $beneficiaryDocument ?? $userCpf,
                'cpf_padrao' => $defaultCpf
            ]);
        }
        
        return [
            'pix_key' => $pixKey,
            'pix_key_type' => $pixKeyType,
            'document' => $document,
            'is_random_key' => $isUUID
        ];
    }

    /**
     * Gera transação de pagamento manual (para aprovação)
     */
    private static function generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        try {
            Log::info('XDPagTrait::generateTransactionPaymentManual - Criando solicitação manual');

            // Garantir ID único para evitar conflitos de chave duplicada
            $externalId = self::ensureUniqueTransactionId($request->idTransaction, $user->user_id);
            
            Log::info('XDPagTrait::generateTransactionPaymentManual - ID da transação:', [
                'id_from_cassino' => $request->idTransaction,
                'external_id_used' => $externalId,
                'is_cassino_id' => !is_null($request->idTransaction),
                'id_foi_modificado' => ($request->idTransaction != $externalId)
            ]);

            // Normalizar dados da chave PIX
            $normalizedData = self::normalizePixKeyData(
                $request->pixKey,
                $request->pixKeyType,
                $request->beneficiaryDocument ?? null,
                $user->cpf ?? null
            );

            Log::info('XDPagTrait::generateTransactionPaymentManual - Dados normalizados:', $normalizedData);

            // Criar solicitação de saque manual com proteção contra ID duplicado
            $solicitacao = self::createCashOutWithRetry([
                'user_id' => $user->user_id,
                'amount' => $request->amount,
                'cash_out_liquido' => $cashout_liquido,
                'taxa_cash_out' => $taxa_cash_out,
                'pix' => $normalizedData['pix_key'],
                'pixkey' => $normalizedData['pix_key_type'],
                'beneficiaryname' => $request->beneficiaryName ?? $user->username,
                'beneficiarydocument' => $normalizedData['document'],
                'idTransaction' => $externalId,
                'externalreference' => $externalId,
                'executor_ordem' => 'xdpag',
                'status' => 'PENDING',
                'date' => $date,
                'callback' => 'web',
                'descricao_transacao' => 'WEB',
                'type' => $normalizedData['is_random_key'] ? 'PIX' : 'PIX'
            ]);

            // Descontar saldo do usuário
            $setting = App::first();
            $taxaPorFora = $setting->taxa_por_fora_api ?? true;
            $valor_total_descontar = $taxaPorFora ? ($request->amount + $taxa_cash_out) : $request->amount;

            if ($taxaPorFora) {
                \App\Helpers\Helper::decrementAmount($user, $valor_total_descontar, 'saldo');
            } else {
                \App\Helpers\Helper::decrementAmount($user, $request->amount, 'saldo');
            }

            \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);

            // Log da operação
            \App\Helpers\BalanceLogHelper::logSaqueOperation(
                'SAQUE_MANUAL',
                $user,
                $request->amount,
                [
                    'adquirente' => 'XDPAG',
                    'valor_bruto' => $request->amount,
                    'valor_liquido' => $cashout_liquido,
                    'taxa_cash_out' => $taxa_cash_out,
                    'taxa_por_fora' => $taxaPorFora,
                    'external_id' => $externalId,
                    'operacao' => 'xdpag_manual_payment'
                ]
            );

            Log::info('XDPagTrait::generateTransactionPaymentManual - Solicitação manual criada com sucesso:', [
                'user_id' => $user->user_id,
                'external_id' => $externalId,
                'amount' => $request->amount,
                'cashout_liquido' => $cashout_liquido,
                'taxa_cash_out' => $taxa_cash_out
            ]);

            return [
                'status' => 200,
                'data' => [
                    'status' => true,
                    'message' => 'Solicitação de saque criada com sucesso. Aguarde aprovação.',
                    'transaction_id' => $externalId,
                    'amount' => $request->amount,
                    'cashout_liquido' => $cashout_liquido,
                    'taxa_cash_out' => $taxa_cash_out,
                    'status' => 'PENDING'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('XDPagTrait::generateTransactionPaymentManual - Exceção:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 500,
                'data' => [
                    'status' => false,
                    'message' => 'Erro interno do servidor',
                    'error' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Libera saque manual (aprovação pelo admin)
     */
    public static function liberarSaqueManual($id)
    {
        try {
            Log::info('=== XDPAGTRAIT LIBERAR SAQUE MANUAL INICIADO ===');
            Log::info('XDPagTrait::liberarSaqueManual - ID da solicitação:', ['id' => $id]);

            $cashout = SolicitacoesCashOut::where('id', $id)->with('user')->first();
            
            if (!$cashout) {
                Log::warning('XDPagTrait::liberarSaqueManual - Solicitação não encontrada:', ['id' => $id]);
                return back()->with('error', 'Solicitação de saque não encontrada.');
            }

            Log::info('XDPagTrait::liberarSaqueManual - Solicitação encontrada:', [
                'id' => $cashout->id,
                'user_id' => $cashout->user_id,
                'amount' => $cashout->amount,
                'cash_out_liquido' => $cashout->cash_out_liquido,
                'status' => $cashout->status,
                'type' => $cashout->type,
                'pix' => $cashout->pix,
                'pixkey' => $cashout->pixkey
            ]);

            $xdpag = new XDPagService();
            $externalId = Str::uuid()->toString();

            if ($cashout->type == "CRYPTO") {
                Log::info('XDPagTrait::liberarSaqueManual - Processando saque CRYPTO (manual)');
                
                // Para crypto, mantém o comportamento manual
                $pixcashout = [
                    "externalreference" => $externalId,
                    "idTransaction" => $externalId,
                    "end_to_end" => $externalId,
                    "descricao_transacao" => "LIBERADOADMIN"
                ];

                Log::info('XDPagTrait::liberarSaqueManual - Atualizando registro CRYPTO:', $pixcashout);
                $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
                Log::info('XDPagTrait::liberarSaqueManual - Registro CRYPTO atualizado com sucesso');
                Log::info('=== FIM XDPAGTRAIT LIBERAR SAQUE MANUAL (CRYPTO) ===');
                return back()->with('success', 'Pedido de saque enviado com sucesso!');
            }

            // Para PIX, processa via API
            Log::info('XDPagTrait::liberarSaqueManual - Processando saque PIX via API');
            
            // Mapear tipos de chave PIX para o formato da API XDPag
            $pixKeyTypeMapping = [
                'cpf' => 'CPF',
                'cnpj' => 'CNPJ',
                'email' => 'EMAIL',
                'telefone' => 'PHONE',
                'phone' => 'PHONE',
                'random' => 'RANDOM',
                'crypto' => 'CRYPTO'
            ];

            $pixKeyType = $pixKeyTypeMapping[strtolower($cashout->pixkey)] ?? strtoupper($cashout->pixkey);

            $paymentData = [
                'amount' => $cashout->cash_out_liquido,
                'external_id' => $externalId,
                'postback_url' => env('APP_URL') . '/api/xdpag/callback/withdraw',
                'description' => 'Saque aprovado pelo admin',
                'pix_key' => $cashout->pix,
                'pix_key_type' => $pixKeyType,
                'beneficiary_name' => $cashout->beneficiaryname,
                'beneficiary_document' => $cashout->beneficiarydocument
            ];

            Log::info('XDPagTrait::liberarSaqueManual - Dados enviados para XDPagService:', $paymentData);

            $response = $xdpag->makePayment($paymentData);

            Log::info('XDPagTrait::liberarSaqueManual - Resposta do XDPagService:', [
                'response' => $response,
                'has_transaction_id' => isset($response['data']['id']),
                'is_error' => isset($response['error'])
            ]);

            // Verificar se houve erro real
            if (!$response) {
                Log::error('XDPagTrait::liberarSaqueManual - Erro na resposta do XDPagService (resposta vazia):', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return back()->with('error', 'Erro ao processar pagamento via XDPag - resposta vazia.');
            }

            // Se há erro explícito, retornar erro
            if (isset($response['error']) && $response['error']) {
                Log::error('XDPagTrait::liberarSaqueManual - Erro explícito na resposta do XDPagService:', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                $errorMessage = $response['message'] ?? 'Erro ao processar pagamento via XDPag';
                return back()->with('error', $errorMessage);
            }

            // Se não tem ID mas a resposta indica sucesso, continuar
            $hasTransactionId = isset($response['data']['id']);
            $isSuccessResponse = isset($response['data']['status']) && in_array($response['data']['status'], ['PENDING', 'PROCESSING', 'APPROVED']);
            
            if (!$hasTransactionId && !$isSuccessResponse) {
                Log::error('XDPagTrait::liberarSaqueManual - Erro na resposta do XDPagService (sem ID e sem sucesso):', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return back()->with('error', 'Erro ao processar pagamento via XDPag.');
            }

            // Se chegou aqui, ou tem ID ou é uma resposta de sucesso
            if (!$hasTransactionId && $isSuccessResponse) {
                Log::info('XDPagTrait::liberarSaqueManual - XDPag retornou sucesso sem ID, salvando transação para callback posterior:', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
            }

            $pixcashout = [
                "externalreference" => $response['data']['id'] ?? $externalId,
                "idTransaction" => $externalId,
                "end_to_end" => $response['data']['id'] ?? $externalId,
                "descricao_transacao" => "LIBERADOADMIN"
            ];

            Log::info('XDPagTrait::liberarSaqueManual - Atualizando registro PIX:', $pixcashout);
            $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
            Log::info('XDPagTrait::liberarSaqueManual - Registro PIX atualizado com sucesso');
            Log::info('=== FIM XDPAGTRAIT LIBERAR SAQUE MANUAL (PIX) ===');
            return back()->with('success', 'Pedido de saque enviado com sucesso!');

        } catch (\Exception $e) {
            Log::error('XDPagTrait::liberarSaqueManual - Exceção capturada:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'id' => $id
            ]);
            Log::info('=== FIM XDPAGTRAIT LIBERAR SAQUE MANUAL (ERRO) ===');
            return back()->with('error', 'Erro interno do servidor: ' . $e->getMessage());
        }
    }
}
