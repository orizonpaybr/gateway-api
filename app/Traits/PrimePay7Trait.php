<?php

namespace App\Traits;

use App\Models\App;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Services\PrimePay7Service;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait PrimePay7Trait
{
    /**
     * Gera QR Code PIX para depósito (Cash-in)
     */
    public static function generateQrCodePrimePay7($request)
    {
        try {
            $data = $request->all();
            $user = User::where('id', $request->user()->id)->first();
            $setting = App::first();

            Log::info('=== PRIMEPAY7TRAIT GENERATE QR CODE INICIADO ===');
            Log::info('PrimePay7Trait::generateQrCodePrimePay7 - Dados da requisição:', [
                'user_id' => $user->id,
                'username' => $user->username,
                'amount' => $request->amount,
                'is_interface_web' => $request->input('baasPostbackUrl') === 'web'
            ]);

            // Determinar se é depósito via interface web ou API
            $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';

            // Calcula taxas de depósito (cash-in) usando sistema flexível
            // Calcular taxas usando TaxaFlexivelHelper (inclui taxas personalizadas)
            $taxaCalculada = \App\Helpers\TaxaFlexivelHelper::calcularTaxaDeposito($request->amount, $setting, $user);
            $deposito_liquido = $taxaCalculada['deposito_liquido'];
            $taxa_cash_in = $taxaCalculada['taxa_cash_in'];
            $descricao = $taxaCalculada['descricao'];
            
            // Definir variáveis para compatibilidade com o código existente
            $taxa_total = $taxa_cash_in;
            $taxa_percentual_valor = $taxa_cash_in; // Para compatibilidade
            $taxa_fixa_deposito = 0; // Para compatibilidade

            Log::info('PrimePay7Trait::generateQrCodePrimePay7 - Cálculo de taxas:', [
                'amount_original' => $request->amount,
                'taxa_cash_in' => $taxa_cash_in,
                'deposito_liquido' => $deposito_liquido,
                'descricao' => $descricao,
                'is_interface_web' => $isInterfaceWeb
            ]);

            $date = Carbon::now();
            $externalId = Str::uuid()->toString();

            // Criar solicitação de depósito
            $solicitacao = Solicitacoes::create([
                'user_id' => $user->user_id,
                'idTransaction' => $externalId,
                'externalreference' => $externalId,
                'amount' => $request->amount,
                'deposito_liquido' => $deposito_liquido,
                'taxa_cash_in' => $taxa_total, // Taxa total (percentual + fixa)
                'taxa_pix_cash_in_adquirente' => $taxa_percentual_valor, // Taxa percentual
                'taxa_pix_cash_in_valor_fixo' => $taxa_fixa_deposito, // Taxa fixa
                'status' => 'WAITING_FOR_APPROVAL',
                'adquirente_ref' => 'primepay7',
                'client_name' => $request->debtor_name ?? 'Cliente',
                'client_document' => $request->debtor_document_number ?? '00000000000',
                'client_email' => $request->email ?? 'cliente@example.com',
                'client_telefone' => $request->phone ?? '00000000000',
                'date' => $date,
                'executor_ordem' => 'primepay7',
                'descricao_transacao' => $descricao,
                'qrcode_pix' => '',
                'paymentcode' => '',
                'paymentCodeBase64' => '',
                'callback' => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/',
                'created_at' => $date,
                'updated_at' => $date
            ]);

            Log::info('PrimePay7Trait::generateQrCodePrimePay7 - Solicitação criada:', [
                'solicitacao_id' => $solicitacao->id,
                'external_id' => $externalId
            ]);

            // Gerar QR Code via PrimePay7
            $primepay7 = new PrimePay7Service();
            $callbackUrl = url('/api/primepay7/callback/deposit');
            
            $qrCodeData = $primepay7->createPixQrCode([
                'amount' => $request->amount,
                'description' => $descricao,
                'external_id' => $externalId,
                'callback_url' => $callbackUrl,
                'expires_in' => 3600 // 1 hora
            ]);

            if (!$qrCodeData || !is_array($qrCodeData)) {
                Log::error('PrimePay7Trait::generateQrCodePrimePay7 - Erro ao gerar QR Code');
                $solicitacao->delete(); // Remove solicitação se falhou
                return [
                    'status' => 500,
                    'data' => [
                        'status' => 'error',
                        'message' => 'Erro ao gerar QR Code PIX'
                    ]
                ];
            }

            // Atualizar solicitação com dados do QR Code
            $qrcode = $qrCodeData['pix']['qrcode'] ?? '';
            $primepay7Id = $qrCodeData['id'] ?? null;
            
            Log::info('PrimePay7Trait::generateQrCodePrimePay7 - Salvando dados da PrimePay7:', [
                'external_id' => $externalId,
                'primepay7_id' => $primepay7Id,
                'qrCodeData_keys' => array_keys($qrCodeData),
                'qrCodeData_sample' => [
                    'id' => $primepay7Id,
                    'status' => $qrCodeData['status'] ?? 'N/A',
                    'amount' => $qrCodeData['amount'] ?? 'N/A'
                ]
            ]);
            
            $solicitacao->update([
                'qrcode_pix' => $qrcode,
                'paymentcode' => $qrCodeData['secureUrl'] ?? '',
                'paymentCodeBase64' => $qrcode ? 'data:image/png;base64,' . base64_encode($qrcode) : '',
                'primepay7_id' => $primepay7Id // Salvar o ID da PrimePay7
            ]);

            Log::info('PrimePay7Trait::generateQrCodePrimePay7 - QR Code gerado e salvo com sucesso');

                    // Usar os campos corretos da resposta da PrimePay7
                    $qrcode = $qrCodeData['pix']['qrcode'] ?? null;
                    $qrCodeImageUrl = $qrcode ? 'https://quickchart.io/qr?text=' . urlencode($qrcode) : '';
                    
                    return [
                        'status' => 200,
                        'data' => [
                            'idTransaction' => $externalId,
                            'qrcode' => $qrcode,
                            'qr_code_image_url' => $qrCodeImageUrl,
                            // Estrutura compatível com o frontend (igual BSPay)
                            'charge' => [
                                'id' => $externalId,
                                'qrCode' => $qrCodeImageUrl,
                                'brCode' => $qrcode
                            ]
                        ]
                    ];

        } catch (\Exception $e) {
            Log::error('PrimePay7Trait::generateQrCodePrimePay7 - Exceção: ' . $e->getMessage());
            return [
                'status' => 500,
                'data' => [
                    'status' => 'error',
                    'message' => 'Erro interno do servidor'
                ]
            ];
        }
    }

    /**
     * Processa pagamento PIX (Cash-out)
     */
    public static function requestPaymentPrimePay7($request)
    {
        try {
            $data = $request->all();
            $user = User::where('id', $request->user()->id)->first();
            $setting = App::first();

            Log::info('=== PRIMEPAY7TRAIT REQUEST PAYMENT INICIADO ===');
            Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Dados da requisição:', [
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

            Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Cálculo de taxas:', [
                'amount_original' => $request->amount,
                'taxa_cash_out' => $taxa_cash_out,
                'cashout_liquido' => $cashout_liquido,
                'descricao' => $descricao,
                'user_saldo' => $user->saldo,
                'is_interface_web' => $isInterfaceWeb
            ]);

            // Verificar saldo considerando taxa por fora
            $saldo_necessario = $valor_total_descontar;
            if ($user->saldo < $saldo_necessario) {
                return [
                    'status' => 401,
                    'data' => [
                        'status' => 'error',
                        'message' => "Saldo insuficiente. Necessário: R$ " . number_format($saldo_necessario, 2, ',', '.') . ", Disponível: R$ " . number_format($user->saldo, 2, ',', '.'),
                    ]
                ];
            }

            $date = Carbon::now();

            // Se for web, verificar se é saque automático
            if ($request->baasPostbackUrl === 'web') {
                Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Interface web detectada:', [
                    'saque_automatico' => $request->has('saque_automatico') ? $request->saque_automatico : false,
                    'has_saque_automatico' => $request->has('saque_automatico')
                ]);
                
                if ($request->has('saque_automatico') && $request->saque_automatico) {
                    Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Processando saque automático');
                    // Processar saque automático diretamente via API
                    return self::processarSaqueAutomaticoPrimePay7($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
                } else {
                    Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Processando saque manual');
                    // Processar como manual (criar solicitação para aprovação)
                    return self::generateTransactionPaymentManualPrimePay7($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
                }
            }

            Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Processando via API (não web)');
            
            $primepay7 = new PrimePay7Service();
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

            // Mapear tipos de chave PIX para o formato da API PrimePay7
            $pixKeyTypeMapping = [
                'cpf' => 'CPF',
                'cnpj' => 'CNPJ',
                'email' => 'EMAIL',
                'telefone' => 'PHONE',
                'phone' => 'PHONE',
                'evp' => 'EVP', // Chave aleatória
            ];
            $primePay7PixKeyType = $pixKeyTypeMapping[strtolower($request->pixKeyType)] ?? 'EVP';

            $payload = [
                'amount' => $cashout_liquido, // Valor líquido a ser enviado
                'description' => $descricao,
                'external_id' => $externalId,
                'callback_url' => url('/api/primepay7/callback/withdraw'),
                'pix_key' => $pixKey,
                'pix_key_type' => $primePay7PixKeyType,
                'beneficiary_name' => $request->beneficiary_name ?? 'Cliente HKPAY',
                'beneficiary_document' => $request->beneficiary_document ?? $pixKey
            ];

            $response = $primepay7->makePayment($payload);

            if ($response && is_array($response)) {
                $solicitacao = SolicitacoesCashOut::create([
                    'user_id' => $user->user_id,
                    'idTransaction' => $response['transaction_id'] ?? $externalId,
                    'externalreference' => $externalId,
                    'amount' => $request->amount,
                    'beneficiaryname' => $request->beneficiary_name ?? $user->name ?? 'Cliente HKPAY',
                    'beneficiarydocument' => $request->beneficiary_document ?? $request->pixKey,
                    'pix' => $request->pixKey,
                    'pixkey' => strtolower($request->pixKeyType),
                    'date' => $date,
                    'status' => 'WAITING_FOR_APPROVAL',
                    'type' => 'PIX',
                    'taxa_cash_out' => $taxa_cash_out,
                    'cash_out_liquido' => $cashout_liquido,
                    'end_to_end' => $response['transaction_id'] ?? $externalId,
                    'descricao_transacao' => $descricao,
                    'callback' => $request->baasPostbackUrl ?? url('/api/primepay7/callback/withdraw'),
                    'primepay7_id' => $response['transaction_id'] ?? $response['id'] ?? null,
                ]);

                Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Saque solicitado e salvo com sucesso.', ['idTransaction' => $externalId]);

                // Debitar saldo do usuário imediatamente (igual ao BSPayTrait)
                $user = User::where('id', $request->user()->id)->first();
                if ($user) {
                    // Para taxa por fora, descontar valor + taxa do saldo
                    $taxaPorFora = \App\Models\App::first()->taxa_por_fora_api ?? true;
                    $valor_para_descontar = $taxaPorFora ? ($request->amount + $taxa_cash_out) : $request->amount;
                    
                    Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Descontando saldo:', [
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
                            'adquirente' => 'PRIMEPAY7',
                            'valor_bruto' => $request->amount,
                            'valor_descontado' => $valor_para_descontar,
                            'taxa_cash_out' => $taxa_cash_out,
                            'taxa_por_fora' => $taxaPorFora,
                            'external_id' => $externalId,
                            'operacao' => 'requestPaymentPrimePay7'
                        ]
                    );
                    
                    Log::info('PrimePay7Trait::requestPaymentPrimePay7 - Saldo debitado com sucesso:', [
                        'user_id' => $user->user_id,
                        'saldo_depois' => $user->fresh()->saldo,
                        'valor_debitado' => $valor_para_descontar
                    ]);
                }

                return [
                    'status' => 200,
                    'data' => [
                        'status' => 'WAITING_FOR_APPROVAL',
                        'idTransaction' => $externalId,
                        'message' => 'Saque solicitado com sucesso. Aguardando processamento.',
                        'amount' => $request->amount,
                        'cash_out_liquido' => $cashout_liquido,
                        'taxa_cash_out' => $taxa_cash_out,
                    ]
                ];
            } else {
                Log::error('PrimePay7Trait::requestPaymentPrimePay7 - Erro ao solicitar saque:', ['response' => $response]);
                return [
                    'status' => 400,
                    'data' => [
                        'status' => 'error',
                        'message' => 'Erro ao solicitar saque PIX',
                        'details' => $response
                    ]
                ];
            }

        } catch (\Exception $e) {
            Log::error('PrimePay7Trait::requestPaymentPrimePay7 - Erro inesperado: ' . $e->getMessage());
            return [
                'status' => 500,
                'data' => [
                    'status' => 'error',
                    'message' => 'Erro interno ao solicitar saque PIX',
                    'details' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Processa saque automático via PrimePay7 (chamado internamente pelo trait)
     */
    private static function processarSaqueAutomaticoPrimePay7($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        Log::info('PrimePay7Trait::processarSaqueAutomaticoPrimePay7 - Iniciando processamento automático.');
        $primepay7 = new PrimePay7Service();
        $externalId = Str::uuid()->toString();

        $pixKey = $request->pixKey;
        switch ($request->pixKeyType) {
            case 'cpf':
            case 'cnpj':
            case 'telefone':
            case 'phone':
                $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                break;
        }

        $pixKeyTypeMapping = [
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'EMAIL',
            'telefone' => 'PHONE',
            'phone' => 'PHONE',
            'evp' => 'EVP',
        ];
        $primePay7PixKeyType = $pixKeyTypeMapping[strtolower($request->pixKeyType)] ?? 'EVP';

        $payload = [
            'amount' => $cashout_liquido,
            'description' => $descricao,
            'external_id' => $externalId,
            'callback_url' => url('/api/primepay7/callback/withdraw'),
            'pix_key' => $pixKey,
            'pix_key_type' => $primePay7PixKeyType,
            'beneficiary_name' => $request->beneficiary_name ?? 'Cliente HKPAY',
            'beneficiary_document' => $request->beneficiary_document ?? $pixKey
        ];

        $response = $primepay7->makePayment($payload);

        if ($response && is_array($response)) {
            $solicitacao = SolicitacoesCashOut::create([
                'user_id' => $user->user_id,
                'idTransaction' => $response['transaction_id'] ?? $externalId,
                'externalreference' => $externalId,
                'amount' => $request->amount,
                'beneficiaryname' => $request->beneficiary_name ?? $user->name ?? 'Cliente HKPAY',
                'beneficiarydocument' => $request->beneficiary_document ?? $request->pixKey,
                'pix' => $request->pixKey,
                'pixkey' => strtolower($request->pixKeyType),
                'date' => $date,
                'status' => 'COMPLETED',
                'type' => 'PIX',
                'taxa_cash_out' => $taxa_cash_out,
                'cash_out_liquido' => $cashout_liquido,
                'end_to_end' => $response['transaction_id'] ?? $externalId,
                'descricao_transacao' => $descricao,
                'callback' => url('/api/primepay7/callback/withdraw'),
                'primepay7_id' => $response['transaction_id'] ?? $response['id'] ?? null,
            ]);

            Log::info('PrimePay7Trait::processarSaqueAutomaticoPrimePay7 - Saque automático solicitado e salvo com sucesso.', ['idTransaction' => $externalId]);

            // Debitar saldo do usuário imediatamente (igual ao BSPayTrait)
            $user = User::where('id', $request->user()->id)->first();
            if ($user) {
                // Para taxa por fora, descontar valor + taxa do saldo
                $taxaPorFora = \App\Models\App::first()->taxa_por_fora_api ?? true;
                $valor_para_descontar = $taxaPorFora ? ($request->amount + $taxa_cash_out) : $request->amount;
                
                Log::info('PrimePay7Trait::processarSaqueAutomaticoPrimePay7 - Descontando saldo:', [
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
                        'adquirente' => 'PRIMEPAY7',
                        'valor_bruto' => $request->amount,
                        'valor_descontado' => $valor_para_descontar,
                        'taxa_cash_out' => $taxa_cash_out,
                        'taxa_por_fora' => $taxaPorFora,
                        'external_id' => $externalId,
                        'operacao' => 'processarSaqueAutomaticoPrimePay7'
                    ]
                );
                
                Log::info('PrimePay7Trait::processarSaqueAutomaticoPrimePay7 - Saldo debitado com sucesso:', [
                    'user_id' => $user->user_id,
                    'saldo_depois' => $user->fresh()->saldo,
                    'valor_debitado' => $valor_para_descontar
                ]);
            }

            return [
                'status' => 200,
                'data' => [
                    'status' => 'WAITING_FOR_APPROVAL',
                    'idTransaction' => $externalId,
                    'message' => 'Saque automático solicitado com sucesso. Aguardando processamento.',
                    'amount' => $request->amount,
                    'cash_out_liquido' => $cashout_liquido,
                    'taxa_cash_out' => $taxa_cash_out,
                ]
            ];
        } else {
            Log::error('PrimePay7Trait::processarSaqueAutomaticoPrimePay7 - Erro ao solicitar saque automático:', ['response' => $response]);
            return [
                'status' => 400,
                'data' => [
                    'status' => 'error',
                    'message' => 'Erro ao solicitar saque automático',
                    'details' => $response
                ]
            ];
        }
    }

    /**
     * Gera uma transação de pagamento manual (para aprovação) via PrimePay7 (chamado internamente pelo trait)
     */
    private static function generateTransactionPaymentManualPrimePay7($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        Log::info('PrimePay7Trait::generateTransactionPaymentManualPrimePay7 - Gerando solicitação de saque manual.');
        $externalId = Str::uuid()->toString(); // ID interno para a solicitação

        SolicitacoesCashOut::create([
            'user_id' => $user->user_id,
            'idTransaction' => $externalId, // ID interno para referência
            'externalreference' => $externalId,
            'amount' => $request->amount,
            'beneficiaryname' => $request->beneficiary_name ?? $user->name ?? 'Cliente HKPAY',
            'beneficiarydocument' => $request->beneficiary_document ?? $request->pixKey,
            'pix' => $request->pixKey,
            'pixkey' => strtolower($request->pixKeyType),
            'date' => $date,
            'status' => 'WAITING_FOR_APPROVAL', // Sempre WAITING_FOR_APPROVAL para manual
            'type' => 'PIX',
            'taxa_cash_out' => $taxa_cash_out,
            'cash_out_liquido' => $cashout_liquido,
            'end_to_end' => $externalId,
            'descricao_transacao' => $descricao,
            'callback' => url('/api/primepay7/callback/withdraw'),
            'primepay7_id' => null, // Manual não tem ID da PrimePay7 ainda
        ]);

        Log::info('PrimePay7Trait::generateTransactionPaymentManualPrimePay7 - Solicitação de saque manual criada com sucesso.', ['idTransaction' => $externalId]);

        return [
            'status' => 200,
            'data' => [
                'status' => 'WAITING_FOR_APPROVAL',
                'idTransaction' => $externalId,
                'message' => 'Solicitação de saque criada com sucesso. Aguardando aprovação manual.',
                'amount' => $request->amount,
                'cash_out_liquido' => $cashout_liquido,
                'taxa_cash_out' => $taxa_cash_out,
            ]
        ];
    }

    /**
     * Libera saque manual (aprovação pelo admin) para PrimePay7
     */
    public static function liberarSaqueManual($id)
    {
        try {
            \Log::info('PrimePay7Trait::liberarSaqueManual - Iniciando liberação manual', ['id' => $id]);

            $cashout = \App\Models\SolicitacoesCashOut::where('id', $id)->first();
            if (!$cashout) {
                \Log::warning('PrimePay7Trait::liberarSaqueManual - Solicitação não encontrada', ['id' => $id]);
                return back()->with('error', 'Solicitação de saque não encontrada.');
            }

            $update = [
                'status' => 'COMPLETED',
                'descricao_transacao' => 'LIBERADOADMIN'
            ];

            \App\Models\SolicitacoesCashOut::where('id', $id)->update($update);
            \Log::info('PrimePay7Trait::liberarSaqueManual - Solicitação atualizada com sucesso', ['id' => $id, 'update' => $update]);
            return back()->with('success', "Saque atualizado para 'PAGO' com sucesso!");
        } catch (\Exception $e) {
            \Log::error('PrimePay7Trait::liberarSaqueManual - Exceção', ['message' => $e->getMessage()]);
            return back()->with('error', 'Erro ao liberar saque manual.');
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
                $solicitacao = \App\Models\SolicitacoesCashOut::create($data);
                
                if ($attempt > 0) {
                    \Log::info('PrimePay7Trait::createCashOutWithRetry - Registro criado após retry:', [
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
                        \Log::error('PrimePay7Trait::createCashOutWithRetry - Falha após múltiplas tentativas:', [
                            'id_original' => $originalId,
                            'ultimo_id_tentado' => $data['idTransaction'],
                            'tentativas' => $attempt,
                            'erro' => $e->getMessage()
                        ]);
                        throw $e;
                    }
                    
                    $newId = \Illuminate\Support\Str::uuid()->toString();
                    $data['idTransaction'] = $newId;
                    
                    \Log::warning('PrimePay7Trait::createCashOutWithRetry - ID duplicado detectado, tentando com novo ID:', [
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
            return \Illuminate\Support\Str::uuid()->toString();
        }

        $existing = \App\Models\SolicitacoesCashOut::where('idTransaction', $requestedId)->first();
        
        if (!$existing) {
            return $requestedId;
        }

        if (in_array($existing->status, ['CANCELLED', 'FAILED', 'REJECTED'])) {
            $newId = $requestedId . '_' . time();
            \Log::warning('PrimePay7Trait::ensureUniqueTransactionId - ID duplicado encontrado (status: ' . $existing->status . '), gerando novo:', [
                'id_original' => $requestedId,
                'id_novo' => $newId,
                'user_id' => $userId,
                'registro_existente_id' => $existing->id,
                'status_existente' => $existing->status
            ]);
            return $newId;
        }

        $newId = \Illuminate\Support\Str::uuid()->toString();
        \Log::warning('PrimePay7Trait::ensureUniqueTransactionId - ID duplicado em uso ativo, gerando UUID novo:', [
            'id_original' => $requestedId,
            'id_novo' => $newId,
            'user_id' => $userId,
            'registro_existente_id' => $existing->id,
            'status_existente' => $existing->status
        ]);
        return $newId;
    }
}