<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\App;
use App\Models\User;
use App\Services\PixupService;
use App\Traits\IPManagementTrait;
use App\Helpers\TaxaFlexivelHelper;

trait PixupTrait
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
    public static function requestDepositPixup($request)
    {
        Log::info('PixupTrait::requestDepositPixup - INÍCIO', [
            'checkout_id' => $request->checkout_id ?? null,
            'metodo' => $request->metodo ?? null,
            'amount' => $request->amount,
            'all_data' => $request->all(),
            'has_checkout_id' => $request->has('checkout_id')
        ]);
        
        try {
            $pixupConfig = \App\Models\Pixup::first();
            if (!$pixupConfig || !$pixupConfig->status) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Pixup não configurado ou inativo."
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
                    Log::info('PixupTrait: Usuário obtido via checkout', [
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

            Log::info('=== PIXUPTRAIT REQUEST DEPOSIT INICIADO ===');
            Log::info('PixupTrait::requestDepositPixup - Dados da requisição:', [
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
            $taxaCalculada = TaxaFlexivelHelper::calcularTaxaDeposito($valor, $setting, $user);
            $valor_liquido = $taxaCalculada['deposito_liquido'];
            $taxa_cash_in = $taxaCalculada['taxa_cash_in'];
            $descricao_taxa = $taxaCalculada['descricao'];

            Log::info('PixupTrait::requestDepositPixup - Cálculo de taxas:', [
                'amount_original' => $valor,
                'taxa_cash_in' => $taxa_cash_in,
                'deposito_liquido' => $valor_liquido,
                'descricao' => $descricao_taxa
            ]);

            $date = Carbon::now();
            $descricao = "Depósito PIX via Pixup - R$ " . number_format($valor, 2, ',', '.');

            $pixup = new PixupService();
            
            // Gera external_id único para a transação
            $externalId = Str::uuid()->toString();

            // Validar CPF/CNPJ antes de enviar para o Pixup
            $documentNumber = $request->debtor_document_number ?? $request->cpf ?? $user->cpf_cnpj ?? null;
            
            // Se não houver documento, gerar um CPF válido para teste
            if (!$documentNumber || $documentNumber === '00000000000') {
                $documentNumber = \App\Helpers\Helper::generateValidCpf();
                Log::info('PixupTrait: Gerando CPF válido para teste', ['cpf_gerado' => $documentNumber]);
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
            
            $qrCodeData = [
                'amount' => $valor,
                'external_id' => $externalId,
                'postback_url' => env('APP_URL') . '/callback',
                'description' => $descricao,
                'debtor_name' => $request->debtor_name ?? $request->name ?? $user->name,
                'debtor_document_number' => $documentNumber,
                'email' => $request->email ?? $user->email,
                'phone' => $request->phone ?? $request->telefone ?? $user->telefone ?? '11999999999'
            ];

            $response = $pixup->generateQrCode($qrCodeData);

            if (!$response || !isset($response['transactionId'])) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao gerar QR Code PIX"
                    ]
                ];
            }

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
                'executor_ordem' => 'pixup',
                'status' => 'WAITING_FOR_APPROVAL',
                'descricao_transacao' => $descricao_taxa,
                'idTransaction' => $externalId,
                'qrcode_pix' => $response['qrcode'] ?? null,
                'paymentcode' => $response['qrcode'] ?? null,
                'paymentCodeBase64' => $response['qrcode'] ?? null,
                'method' => 'PIX',
                'adquirente_ref' => 'pixup',
                'callback' => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/',
                'split_email' => $request->split_email ?? null,
                'split_percentage' => $request->split_percentage ?? null,
                'date' => $date,
                'created_at' => $date,
                'updated_at' => $date
            ]);

            Log::info('PixupTrait::requestDepositPixup - Registro de solicitação criado:', [
                'solicitacao_id' => $solicitacao->id,
                'externalreference' => $solicitacao->externalreference,
                'amount' => $solicitacao->amount,
                'deposito_liquido' => $solicitacao->deposito_liquido,
                'taxa_cash_in' => $solicitacao->taxa_cash_in
            ]);

            Log::info('=== PIXUPTRAIT REQUEST DEPOSIT FINALIZADO ===');

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Cobrança PIX criada com sucesso",
                    "idTransaction" => $externalId,
                    "qrcode" => $response['qrcode'] ?? null,
                    "qr_code_image_url" => $response['qr_code_image_url'] ?? 'https://quickchart.io/qr?text=' . urlencode($response['qrcode'] ?? ''),
                    "charge" => [
                        "id" => $externalId,
                        "value" => $valor,
                        "qrCode" => $response['qr_code_image_url'] ?? 'https://quickchart.io/qr?text=' . urlencode($response['qrcode'] ?? ''),
                        "brCode" => $response['qrcode'] ?? null,
                        "pixKey" => null,
                        "expiresAt" => null
                    ],
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no PixupTrait::requestDepositPixup: ' . $e->getMessage());
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
     * Processa saque via PIX (Cash-out)
     */
    public static function requestPaymentPixup($request)
    {
        try {
            $data = $request->all();
            $user = User::where('id', $request->user()->id)->first();
            $setting = App::first();

            Log::info('=== PIXUPTRAIT REQUEST PAYMENT INICIADO ===');
            Log::info('PixupTrait::requestPaymentPixup - Dados da requisição:', [
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

            Log::info('PixupTrait::requestPaymentPixup - Cálculo de taxas:', [
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
                // Calcular valor máximo que pode ser sacado
                $valorMaximo = \App\Helpers\TaxaSaqueHelper::calcularValorMaximoSaque($user->saldo, $setting, $user, $isInterfaceWeb);
                
                Log::warning('PixupTrait::requestPaymentPixup - Saldo insuficiente:', [
                    'user_saldo' => $user->saldo,
                    'valor_solicitado' => $request->amount,
                    'valor_total_descontar' => $saldo_necessario,
                    'valor_maximo_saque' => $valorMaximo['valor_maximo'],
                    'taxa_total' => $valorMaximo['taxa_total']
                ]);
                
                return [
                    'status' => 401,
                    'data' => [
                        'message' => "Saldo insuficiente para realizar a operação. Considere o valor + a taxa de saque.",
                        'valor_solicitado' => $request->amount,
                        'taxa_total' => $taxa_cash_out,
                        'valor_total_necessario' => $saldo_necessario,
                        'saldo_disponivel' => $user->saldo,
                        'deficit' => $saldo_necessario - $user->saldo,
                        'valor_maximo_saque' => $valorMaximo['valor_maximo'],
                        'saldo_restante' => $valorMaximo['saldo_restante']
                    ]
                ];
            }

            $date = Carbon::now();

            // Se for web, verificar se é saque automático
            if ($request->baasPostbackUrl === 'web') {
                Log::info('PixupTrait::requestPaymentPixup - Interface web detectada:', [
                    'saque_automatico' => $request->has('saque_automatico') ? $request->saque_automatico : false,
                    'has_saque_automatico' => $request->has('saque_automatico')
                ]);
                
                if ($request->has('saque_automatico') && $request->saque_automatico) {
                    Log::info('PixupTrait::requestPaymentPixup - Processando saque automático');
                    // Processar saque automático diretamente via API
                    return self::processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
                } else {
                    Log::info('PixupTrait::requestPaymentPixup - Processando saque manual');
                    // Processar como manual (criar solicitação para aprovação)
                    return self::generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
                }
            }

            Log::info('PixupTrait::requestPaymentPixup - Processando via API (não web)');
            
            $pixup = new PixupService();
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

            // Mapear tipos de chave PIX para o formato da API Pixup
            $pixKeyTypeMapping = [
                'cpf' => 'CPF',
                'cnpj' => 'CNPJ', 
                'email' => 'EMAIL',
                'telefone' => 'PHONE',
                'phone' => 'PHONE',
                'aleatoria' => 'RANDOM',
                'random' => 'RANDOM',
                'crypto' => 'CRYPTO'
            ];
            
            $pixKeyType = $pixKeyTypeMapping[strtolower($request->pixKeyType)] ?? strtoupper($request->pixKeyType);

            Log::info('PixupTrait::requestPaymentPixup - Dados PIX processados:', [
                'pix_key_original' => $request->pixKey,
                'pix_key_processed' => $pixKey,
                'pix_key_type_original' => $request->pixKeyType,
                'pix_key_type_mapped' => $pixKeyType,
                'external_id' => $externalId
            ]);

            $paymentData = [
                'amount' => $request->amount,
                'external_id' => $externalId,
                'postback_url' => env('APP_URL') . '/callback',
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'beneficiary_name' => $request->user()->name,
                'beneficiary_document' => $pixKey
            ];

            Log::info('PixupTrait::requestPaymentPixup - Dados enviados para PixupService:', $paymentData);

            $response = $pixup->makePayment($paymentData);

            Log::info('PixupTrait::requestPaymentPixup - Resposta do PixupService:', [
                'response' => $response,
                'has_transaction_id' => isset($response['transactionId']),
                'is_error' => isset($response['error'])
            ]);

            // Verificar se houve erro real ou apenas falta do transactionId
            if (!$response) {
                Log::error('PixupTrait::requestPaymentPixup - Erro na resposta do PixupService (resposta vazia):', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return [
                    'status' => 500,
                    'data' => [
                        'message' => 'Erro ao processar pagamento via Pixup - resposta vazia',
                        'pixup_error' => true,
                        'details' => $response['details'] ?? null,
                        'pixup_raw_response' => $response['raw_response'] ?? null
                    ]
                ];
            }

            // Se há erro explícito, retornar erro
            if (isset($response['error']) && $response['error']) {
                Log::error('PixupTrait::requestPaymentPixup - Erro explícito na resposta do PixupService:', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return [
                    'status' => 500,
                    'data' => [
                        'message' => 'Erro ao processar pagamento via Pixup',
                        'pixup_error' => true,
                        'details' => $response['details'] ?? null,
                        'pixup_raw_response' => $response['raw_response'] ?? null
                    ]
                ];
            }

            // Se não tem transactionId mas a resposta indica sucesso (statusCode 200), continuar
            $hasTransactionId = isset($response['transactionId']);
            $isSuccessResponse = isset($response['statusCode']) && $response['statusCode'] == 200;
            
            if (!$hasTransactionId && !$isSuccessResponse) {
                Log::error('PixupTrait::requestPaymentPixup - Erro na resposta do PixupService (sem transactionId e sem sucesso):', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return [
                    'status' => 500,
                    'data' => [
                        'message' => 'Erro ao processar pagamento via Pixup',
                        'pixup_error' => true,
                        'details' => $response['details'] ?? null,
                        'pixup_raw_response' => $response['raw_response'] ?? null
                    ]
                ];
            }

            // Se chegou aqui, ou tem transactionId ou é uma resposta de sucesso
            // Vamos salvar a transação para permitir o callback posterior
            if (!$hasTransactionId && $isSuccessResponse) {
                Log::info('PixupTrait::requestPaymentPixup - Pixup retornou sucesso sem transactionId, salvando transação para callback posterior:', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
            }

            // Criar registro de saque
            $cashout = [
                "user_id" => $request->user()->username,
                "externalreference" => $externalId,
                "amount" => $request->amount,
                "cash_out_liquido" => $cashout_liquido,
                "taxa_cash_out" => $taxa_cash_out,
                "pix" => $pixKey,
                "pixkey" => $pixKeyType,
                "beneficiaryname" => $request->user()->name,
                "beneficiarydocument" => $pixKey,
                "date" => $date,
                "status" => 'PENDING',
                "idTransaction" => $externalId,
                "end_to_end" => $externalId,
                "descricao_transacao" => $descricao,
                "executor_ordem" => 'pixup',
                "type" => "PIX",
                "callback" => $request->baasPostbackUrl === 'web' ? env('APP_URL') . '/callback' : $request->baasPostbackUrl
            ];

            Log::info('PixupTrait::requestPaymentPixup - Criando registro de saque:', $cashout);
            $solicitacao = SolicitacoesCashOut::create($cashout);
            Log::info('PixupTrait::requestPaymentPixup - Registro de saque criado com sucesso');

            // Debitar saldo do usuário imediatamente
            $user = User::where('id', $request->user()->id)->first();
            if ($user) {
                // Para taxa por fora, descontar valor + taxa do saldo
                $taxaPorFora = \App\Models\App::first()->taxa_por_fora_api ?? true;
                $valor_para_descontar = $taxaPorFora ? $valor_total_descontar : $request->amount;
                
                Log::info('PixupTrait::requestPaymentPixup - Descontando saldo:', [
                    'user_id' => $user->user_id,
                    'saldo_antes' => $user->saldo,
                    'valor_para_descontar' => $valor_para_descontar,
                    'taxa_por_fora' => $taxaPorFora
                ]);
                
                \App\Helpers\Helper::decrementAmount($user, $valor_para_descontar, 'saldo');
                $user->increment('valor_sacado', $request->amount);
                
                // Log específico para saque
                \App\Helpers\BalanceLogHelper::logSaqueOperation(
                    'SAQUE_REQUEST',
                    $user,
                    $request->amount,
                    [
                        'adquirente' => 'PIXUP',
                        'valor_bruto' => $request->amount,
                        'valor_descontado' => $valor_para_descontar,
                        'taxa_cash_out' => $taxa_cash_out,
                        'taxa_por_fora' => $taxaPorFora,
                        'external_id' => $externalId,
                        'operacao' => 'requestPaymentPixup'
                    ]
                );
                
                Log::info('PixupTrait::requestPaymentPixup - Saldo atualizado:', [
                    'user_id' => $user->user_id,
                    'saldo_depois' => $user->fresh()->saldo,
                    'valor_sacado' => $user->fresh()->valor_sacado
                ]);
            }

            $responseData = [
                'status' => 200,
                'data' => [
                    'id' => $externalId,
                    'amount' => $request->amount,
                    'pixKey' => $pixKey,
                    'pixKeyType' => $pixKeyType,
                    'withdrawStatusId' => 'PendingProcessing',
                    'createdAt' => $date->toISOString(),
                    'updatedAt' => $date->toISOString()
                ]
            ];

            Log::info('PixupTrait::requestPaymentPixup - Resposta final:', $responseData);
            Log::info('=== FIM PIXUPTRAIT REQUEST PAYMENT ===');

            return $responseData;

        } catch (\Exception $e) {
            Log::error('PixupTrait::requestPaymentPixup - Exceção capturada:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'status' => 500,
                'data' => ['message' => 'Erro interno do servidor']
            ];
        }
    }

    /**
     * Processa saque automático via Pixup
     */
    private static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        try {
            Log::info('=== PIXUPTRAIT PROCESSAR SAQUE AUTOMÁTICO INICIADO ===');
            Log::info('PixupTrait::processarSaqueAutomatico - Dados recebidos:', [
                'user_id' => $user->id,
                'username' => $user->username,
                'amount' => $request->amount,
                'taxa_cash_out' => $taxa_cash_out,
                'cashout_liquido' => $cashout_liquido,
                'descricao' => $descricao
            ]);

            $pixup = new PixupService();
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

            // Mapear tipos de chave PIX
            $pixKeyTypeMapping = [
                'cpf' => 'CPF',
                'cnpj' => 'CNPJ', 
                'email' => 'EMAIL',
                'telefone' => 'PHONE',
                'phone' => 'PHONE',
                'aleatoria' => 'RANDOM',
                'random' => 'RANDOM',
                'crypto' => 'CRYPTO'
            ];
            
            $pixKeyType = $pixKeyTypeMapping[strtolower($request->pixKeyType)] ?? strtoupper($request->pixKeyType);

            Log::info('PixupTrait::processarSaqueAutomatico - Dados PIX processados:', [
                'pix_key_original' => $request->pixKey,
                'pix_key_processed' => $pixKey,
                'pix_key_type_original' => $request->pixKeyType,
                'pix_key_type_mapped' => $pixKeyType,
                'external_id' => $externalId
            ]);

            $paymentData = [
                'amount' => $request->amount,
                'external_id' => $externalId,
                'postback_url' => env('APP_URL') . '/callback',
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'beneficiary_name' => $request->user()->name,
                'beneficiary_document' => $pixKey
            ];

            Log::info('PixupTrait::processarSaqueAutomatico - Dados enviados para PixupService:', $paymentData);

            $response = $pixup->makePayment($paymentData);

            Log::info('PixupTrait::processarSaqueAutomatico - Resposta do PixupService:', [
                'response' => $response,
                'has_transaction_id' => isset($response['transactionId']),
                'is_error' => isset($response['error'])
            ]);

            // Verificar se houve erro real ou apenas falta do transactionId
            if (!$response) {
                Log::error('PixupTrait::processarSaqueAutomatico - Erro na resposta do PixupService (resposta vazia):', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return [
                    'status' => 500,
                    'data' => [
                        'message' => 'Erro ao processar saque automático via Pixup - resposta vazia',
                        'pixup_error' => true,
                        'details' => $response['details'] ?? null,
                        'pixup_raw_response' => $response['raw_response'] ?? null
                    ]
                ];
            }

            // Se há erro explícito, retornar erro
            if (isset($response['error']) && $response['error']) {
                Log::error('PixupTrait::processarSaqueAutomatico - Erro explícito na resposta do PixupService:', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return [
                    'status' => 500,
                    'data' => [
                        'message' => 'Erro ao processar saque automático via Pixup',
                        'pixup_error' => true,
                        'details' => $response['details'] ?? null,
                        'pixup_raw_response' => $response['raw_response'] ?? null
                    ]
                ];
            }

            // Se não tem transactionId mas a resposta indica sucesso (statusCode 200), continuar
            $hasTransactionId = isset($response['transactionId']);
            $isSuccessResponse = isset($response['statusCode']) && $response['statusCode'] == 200;
            
            if (!$hasTransactionId && !$isSuccessResponse) {
                Log::error('PixupTrait::processarSaqueAutomatico - Erro na resposta do PixupService (sem transactionId e sem sucesso):', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return [
                    'status' => 500,
                    'data' => [
                        'message' => 'Erro ao processar saque automático via Pixup',
                        'pixup_error' => true,
                        'details' => $response['details'] ?? null,
                        'pixup_raw_response' => $response['raw_response'] ?? null
                    ]
                ];
            }

            // Se chegou aqui, ou tem transactionId ou é uma resposta de sucesso
            // Vamos salvar a transação para permitir o callback posterior
            if (!$hasTransactionId && $isSuccessResponse) {
                Log::info('PixupTrait::processarSaqueAutomatico - Pixup retornou sucesso sem transactionId, salvando transação para callback posterior:', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
            }

            // Criar registro de saque
            $cashout = [
                "user_id" => $request->user()->username,
                "externalreference" => $externalId,
                "amount" => $request->amount,
                "cash_out_liquido" => $cashout_liquido,
                "taxa_cash_out" => $taxa_cash_out,
                "pix" => $pixKey,
                "pixkey" => $pixKeyType,
                "beneficiaryname" => $request->user()->name,
                "beneficiarydocument" => $pixKey,
                "date" => $date,
                "status" => 'PENDING',
                "idTransaction" => $externalId,
                "end_to_end" => $externalId,
                "descricao_transacao" => $descricao,
                "executor_ordem" => 'pixup',
                "type" => "PIX",
                "callback" => $request->baasPostbackUrl === 'web' ? env('APP_URL') . '/callback' : $request->baasPostbackUrl
            ];

            Log::info('PixupTrait::processarSaqueAutomatico - Criando registro de saque:', $cashout);
            $solicitacao = SolicitacoesCashOut::create($cashout);
            Log::info('PixupTrait::processarSaqueAutomatico - Registro de saque criado com sucesso');

            // Atualizar saldo do usuário (Jhon Martins)
            // Para taxa por fora, descontar valor + taxa do saldo
            $taxaPorFora = \App\Models\App::first()->taxa_por_fora_api ?? true;
            $valor_para_descontar = $taxaPorFora ? ($request->amount + $taxa_cash_out) : $request->amount;
            
            Log::info('PixupTrait::processarSaqueAutomatico - Descontando saldo:', [
                'user_id' => $user->user_id,
                'saldo_antes' => $user->saldo,
                'valor_para_descontar' => $valor_para_descontar,
                'taxa_por_fora' => $taxaPorFora
            ]);
            
            \App\Helpers\Helper::decrementAmount($user, $valor_para_descontar, 'saldo');
            $user->increment('valor_sacado', $request->amount);
            
            Log::info('PixupTrait::processarSaqueAutomatico - Saldo atualizado:', [
                'user_id' => $user->user_id,
                'saldo_depois' => $user->fresh()->saldo,
                'valor_sacado' => $user->fresh()->valor_sacado
            ]);

            $responseData = [
                'status' => 200,
                'data' => [
                    'id' => $externalId,
                    'amount' => $request->amount,
                    'pixKey' => $pixKey,
                    'pixKeyType' => $pixKeyType,
                    'withdrawStatusId' => 'PendingProcessing',
                    'createdAt' => $date->toISOString(),
                    'updatedAt' => $date->toISOString()
                ]
            ];

            Log::info('PixupTrait::processarSaqueAutomatico - Resposta final:', $responseData);
            Log::info('=== FIM PIXUPTRAIT PROCESSAR SAQUE AUTOMÁTICO ===');

            return $responseData;

        } catch (\Exception $e) {
            Log::error('PixupTrait::processarSaqueAutomatico - Exceção capturada:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
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

            // Mapear tipos de chave PIX
            $pixKeyTypeMapping = [
                'cpf' => 'CPF',
                'cnpj' => 'CNPJ', 
                'email' => 'EMAIL',
                'telefone' => 'PHONE',
                'phone' => 'PHONE',
                'aleatoria' => 'RANDOM',
                'random' => 'RANDOM',
                'crypto' => 'CRYPTO'
            ];
            
            $pixKeyType = $pixKeyTypeMapping[strtolower($request->pixKeyType)] ?? strtoupper($request->pixKeyType);

            // Criar registro de saque manual
            $cashout = [
                "user_id" => $request->user()->username,
                "externalreference" => $externalId,
                "amount" => $request->amount,
                "cash_out_liquido" => $cashout_liquido,
                "taxa_cash_out" => $taxa_cash_out,
                "pix" => $pixKey,
                "pixkey" => $pixKeyType,
                "beneficiaryname" => $request->user()->name,
                "beneficiarydocument" => $pixKey,
                "date" => $date,
                "status" => 'PENDING_APPROVAL',
                "idTransaction" => $externalId,
                "end_to_end" => $externalId,
                "descricao_transacao" => $descricao,
                "executor_ordem" => 'pixup',
                "type" => "PIX",
                "callback" => $request->baasPostbackUrl === 'web' ? env('APP_URL') . '/callback' : $request->baasPostbackUrl
            ];

            SolicitacoesCashOut::create($cashout);

            return [
                'status' => 200,
                'data' => [
                    'id' => $externalId,
                    'amount' => $request->amount,
                    'pixKey' => $pixKey,
                    'pixKeyType' => $pixKeyType,
                    'withdrawStatusId' => 'PendingApproval',
                    'createdAt' => $date->toISOString(),
                    'updatedAt' => $date->toISOString(),
                    'message' => 'Solicitação de saque criada e aguardando aprovação'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no PixupTrait::generateTransactionPaymentManual: ' . $e->getMessage());
            return [
                'status' => 500,
                'data' => ['message' => 'Erro interno do servidor']
            ];
        }
    }

    /**
     * Libera saque manual via Pixup
     */
    public static function liberarSaqueManual($id)
    {
        try {
            Log::info('=== PIXUPTRAIT LIBERAR SAQUE MANUAL INICIADO ===');
            Log::info('PixupTrait::liberarSaqueManual - ID da solicitação:', ['id' => $id]);

            $cashout = SolicitacoesCashOut::where('id', $id)->with('user')->first();
            
            if (!$cashout) {
                Log::warning('PixupTrait::liberarSaqueManual - Solicitação não encontrada:', ['id' => $id]);
                return back()->with('error', 'Solicitação de saque não encontrada.');
            }

            Log::info('PixupTrait::liberarSaqueManual - Solicitação encontrada:', [
                'id' => $cashout->id,
                'user_id' => $cashout->user_id,
                'amount' => $cashout->amount,
                'cash_out_liquido' => $cashout->cash_out_liquido,
                'status' => $cashout->status,
                'type' => $cashout->type,
                'pix' => $cashout->pix,
                'pixkey' => $cashout->pixkey
            ]);

            $pixup = new PixupService();
            $externalId = Str::uuid()->toString();

            if ($cashout->type == "CRYPTO") {
                Log::info('PixupTrait::liberarSaqueManual - Processando saque CRYPTO (manual)');
                
                // Para crypto, mantém o comportamento manual
                $pixcashout = [
                    "externalreference" => $externalId,
                    "idTransaction" => $externalId,
                    "end_to_end" => $externalId,
                    "descricao_transacao" => "LIBERADOADMIN"
                ];

                Log::info('PixupTrait::liberarSaqueManual - Atualizando registro CRYPTO:', $pixcashout);
                $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
                Log::info('PixupTrait::liberarSaqueManual - Registro CRYPTO atualizado com sucesso');
                Log::info('=== FIM PIXUPTRAIT LIBERAR SAQUE MANUAL (CRYPTO) ===');
                return back()->with('success', 'Pedido de saque enviado com sucesso!');
            }

            // Para PIX, processa via API
            Log::info('PixupTrait::liberarSaqueManual - Processando saque PIX via API');
            
            $paymentData = [
                'amount' => $cashout->cash_out_liquido,
                'external_id' => $externalId,
                'postback_url' => env('APP_URL') . '/callback',
                'pix_key' => $cashout->pix,
                'pix_key_type' => strtoupper($cashout->pixkey),
                'beneficiary_name' => $cashout->beneficiaryname,
                'beneficiary_document' => $cashout->beneficiarydocument
            ];

            Log::info('PixupTrait::liberarSaqueManual - Dados enviados para PixupService:', $paymentData);

            $response = $pixup->makePayment($paymentData);

            Log::info('PixupTrait::liberarSaqueManual - Resposta do PixupService:', [
                'response' => $response,
                'has_transaction_id' => isset($response['transactionId']),
                'is_error' => isset($response['error'])
            ]);

            // Verificar se houve erro real ou apenas falta do transactionId
            if (!$response) {
                Log::error('PixupTrait::liberarSaqueManual - Erro na resposta do PixupService (resposta vazia):', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return back()->with('error', 'Erro ao processar pagamento via Pixup - resposta vazia.');
            }

            // Se há erro explícito, retornar erro
            if (isset($response['error']) && $response['error']) {
                Log::error('PixupTrait::liberarSaqueManual - Erro explícito na resposta do PixupService:', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return back()->with('error', 'Erro ao processar pagamento via Pixup.');
            }

            // Se não tem transactionId mas a resposta indica sucesso (statusCode 200), continuar
            $hasTransactionId = isset($response['transactionId']);
            $isSuccessResponse = isset($response['statusCode']) && $response['statusCode'] == 200;
            
            if (!$hasTransactionId && !$isSuccessResponse) {
                Log::error('PixupTrait::liberarSaqueManual - Erro na resposta do PixupService (sem transactionId e sem sucesso):', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
                return back()->with('error', 'Erro ao processar pagamento via Pixup.');
            }

            // Se chegou aqui, ou tem transactionId ou é uma resposta de sucesso
            // Vamos salvar a transação para permitir o callback posterior
            if (!$hasTransactionId && $isSuccessResponse) {
                Log::info('PixupTrait::liberarSaqueManual - Pixup retornou sucesso sem transactionId, salvando transação para callback posterior:', [
                    'response' => $response,
                    'external_id' => $externalId
                ]);
            }

            $pixcashout = [
                "externalreference" => $externalId,
                "idTransaction" => $externalId,
                "end_to_end" => $externalId,
                "descricao_transacao" => "LIBERADOADMIN"
            ];

            Log::info('PixupTrait::liberarSaqueManual - Atualizando registro PIX:', $pixcashout);
            $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
            Log::info('PixupTrait::liberarSaqueManual - Registro PIX atualizado com sucesso');
            Log::info('=== FIM PIXUPTRAIT LIBERAR SAQUE MANUAL (PIX) ===');
            return back()->with('success', 'Pedido de saque enviado com sucesso!');

        } catch (\Exception $e) {
            Log::error('PixupTrait::liberarSaqueManual - Exceção capturada:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'id' => $id
            ]);
            Log::info('=== FIM PIXUPTRAIT LIBERAR SAQUE MANUAL (ERRO) ===');
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
                    Log::info('PixupTrait::createCashOutWithRetry - Registro criado após retry:', [
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
                        Log::error('PixupTrait::createCashOutWithRetry - Falha após múltiplas tentativas:', [
                            'id_original' => $originalId,
                            'ultimo_id_tentado' => $data['idTransaction'],
                            'tentativas' => $attempt,
                            'erro' => $e->getMessage()
                        ]);
                        throw $e;
                    }
                    
                    $newId = Str::uuid()->toString();
                    $data['idTransaction'] = $newId;
                    
                    Log::warning('PixupTrait::createCashOutWithRetry - ID duplicado detectado, tentando com novo ID:', [
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
            Log::warning('PixupTrait::ensureUniqueTransactionId - ID duplicado encontrado (status: ' . $existing->status . '), gerando novo:', [
                'id_original' => $requestedId,
                'id_novo' => $newId,
                'user_id' => $userId,
                'registro_existente_id' => $existing->id,
                'status_existente' => $existing->status
            ]);
            return $newId;
        }

        $newId = Str::uuid()->toString();
        Log::warning('PixupTrait::ensureUniqueTransactionId - ID duplicado em uso ativo, gerando UUID novo:', [
            'id_original' => $requestedId,
            'id_novo' => $newId,
            'user_id' => $userId,
            'registro_existente_id' => $existing->id,
            'status_existente' => $existing->status
        ]);
        return $newId;
    }
}