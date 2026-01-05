<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\App;
use App\Models\User;
use App\Helpers\Helper;
use App\Services\PagArmService;
use App\Traits\IPManagementTrait;
use App\Helpers\TaxaFlexivelHelper;

trait PagArmTrait
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
    public static function requestDepositPagArm($request)
    {
        Log::info('PagArmTrait::requestDepositPagArm - Início', [
            'checkout_id' => $request->checkout_id ?? null,
            'metodo' => $request->metodo ?? null,
            'amount' => $request->amount,
            'all_data' => $request->all()
        ]);
        
        try {
            $pagarmConfig = \App\Models\PagArm::first();
            if (!$pagarmConfig || !$pagarmConfig->status) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "PagArm não configurado ou inativo."
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

            $valor = (float) ($request->amount ?? $request->valor_total);
            $setting = \App\Models\App::first();

            Log::info('=== PAGARM TRAIT REQUEST DEPOSIT INICIADO ===');
            Log::info('PagArmTrait::requestDepositPagArm - Dados:', [
                'user_id' => $user->id,
                'username' => $user->username,
                'amount' => $valor
            ]);

            // Calcular taxa usando o sistema flexível
            $taxaCalculada = TaxaFlexivelHelper::calcularTaxaDeposito($valor, $setting, $user);
            $deposito_liquido = $taxaCalculada['deposito_liquido'];
            $taxa_cash_in = $taxaCalculada['taxa_cash_in'];

            Log::info('PagArmTrait::requestDepositPagArm - Cálculo de taxas:', [
                'valor_bruto' => $valor,
                'taxa_cash_in' => $taxa_cash_in,
                'deposito_liquido' => $deposito_liquido,
                'descricao' => $taxaCalculada['descricao']
            ]);

            // Gerar ID único para a transação
            $externalId = Str::uuid()->toString();

            // Preparar dados para PagArm
            $paymentData = [
                'amount' => $valor,
                'external_id' => $externalId,
                'postback_url' => env('APP_URL') . '/api/callback/pagarm/deposit',
                'description' => 'Depósito via PagArm - ' . $user->username,
                'debtor_name' => $user->name ?? 'Cliente',
                'debtor_document_number' => $user->cpf_cnpj ?? '00000000000',
                'email' => $user->email ?? 'cliente@pagarm.com.br',
                'phone' => $user->telefone ?? '11999999999'
            ];

            Log::info('PagArmTrait::requestDepositPagArm - Dados enviados para PagArmService:', $paymentData);

            $pagarm = new PagArmService();
            $response = $pagarm->generateQrCode($paymentData);

            Log::info('PagArmTrait::requestDepositPagArm - Resposta do PagArmService:', [
                'response' => $response,
                'has_qr_code' => isset($response['qr_code']),
                'has_transaction_id' => isset($response['transaction_id'])
            ]);

            if (!$response || !isset($response['transaction_id'])) {
                Log::error('PagArmTrait::requestDepositPagArm - Erro na resposta da API');
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao gerar QR Code PagArm."
                    ]
                ];
            }

            // Criar solicitação no banco
            $solicitacao = Solicitacoes::create([
                'user_id' => $user->username,
                'amount' => $valor,
                'deposito_liquido' => $deposito_liquido,
                'taxa_cash_in' => $taxa_cash_in,
                'idTransaction' => $response['transaction_id'],
                'external_id' => $externalId,
                'status' => 'PENDING',
                'adquirente' => 'pagarm',
                'metodo' => 'pix',
                'date' => Carbon::now(),
                'qr_code' => $response['qr_code'] ?? null,
                'qr_code_text' => $response['qr_code_text'] ?? null,
                'pix_key' => $response['pix_key'] ?? null,
                'pix_key_type' => $response['pix_key_type'] ?? null
            ]);

            Log::info('PagArmTrait::requestDepositPagArm - Solicitação criada:', [
                'solicitacao_id' => $solicitacao->id,
                'transaction_id' => $response['transaction_id']
            ]);

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "QR Code gerado com sucesso",
                    "transaction_id" => $response['transaction_id'],
                    "external_id" => $externalId,
                    "qr_code" => $response['qr_code'] ?? null,
                    "qr_code_text" => $response['qr_code_text'] ?? null,
                    "pix_key" => $response['pix_key'] ?? null,
                    "pix_key_type" => $response['pix_key_type'] ?? null,
                    "amount" => $valor,
                    "deposito_liquido" => $deposito_liquido,
                    "taxa_cash_in" => $taxa_cash_in,
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('PagArmTrait::requestDepositPagArm - Exceção:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro interno: " . $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Processa saque via PIX (Cash-out)
     */
    public static function requestPaymentPagArm($request)
    {
        try {
            $data = $request->all();
            $user = User::where('id', $request->user()->id)->first();
            $setting = App::first();

            Log::info('=== PAGARM TRAIT REQUEST PAYMENT INICIADO ===');
            Log::info('PagArmTrait::requestPaymentPagArm - Dados:', [
                'user_id' => $user->id,
                'username' => $user->username,
                'amount' => $request->amount,
                'pix_key' => $request->pixKey,
                'pix_key_type' => $request->pixKeyType
            ]);

            // Verificar IP autorizado
            $ipCheck = self::checkIPForWithdraw($user);
            if (!$ipCheck['success']) {
                return [
                    "status" => 403,
                    "data" => [
                        "status" => "error",
                        "message" => $ipCheck['message']
                    ]
                ];
            }

            // Determinar se é saque via interface web ou API
            $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';

            // Verificar se deve usar taxa por fora para saques via API
            $taxaPorFora = $setting->taxa_por_fora_api ?? true;

            // Calcular taxas de saque
            $taxaCalculada = \App\Helpers\TaxaSaqueHelper::calcularTaxaSaque(
                (float)$request->amount, 
                $setting, 
                $user, 
                $isInterfaceWeb, 
                $taxaPorFora
            );
            
            $cashout_liquido = $taxaCalculada['saque_liquido'];
            $taxa_cash_out = $taxaCalculada['taxa_cash_out'];
            $valor_total_descontar = $taxaCalculada['valor_total_descontar'];

            Log::info('PagArmTrait::requestPaymentPagArm - Cálculo de taxas:', [
                'amount_solicitado' => $request->amount,
                'taxa_cash_out' => $taxa_cash_out,
                'cashout_liquido' => $cashout_liquido,
                'valor_total_descontar' => $valor_total_descontar
            ]);

            // Verificar saldo suficiente
            if ($user->saldo < $valor_total_descontar) {
                return [
                    "status" => 400,
                    "data" => [
                        "status" => "error",
                        "message" => "Saldo insuficiente para realizar o saque."
                    ]
                ];
            }

            // Gerar ID único para a transação
            $externalId = Str::uuid()->toString();

            // Preparar dados do PIX
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
                'random' => 'RANDOM'
            ];
            
            $pixKeyType = $pixKeyTypeMapping[strtolower($request->pixKeyType)] ?? 'CPF';

            $paymentData = [
                'amount' => $request->amount,
                'external_id' => $externalId,
                'postback_url' => env('APP_URL') . '/api/callback/pagarm/withdraw',
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'beneficiary_name' => $user->name,
                'beneficiary_document' => $pixKey,
                'description' => 'Saque via PagArm - ' . $user->username
            ];

            Log::info('PagArmTrait::requestPaymentPagArm - Dados enviados para PagArmService:', $paymentData);

            $pagarm = new PagArmService();
            $response = $pagarm->makePayment($paymentData);

            Log::info('PagArmTrait::requestPaymentPagArm - Resposta do PagArmService:', [
                'response' => $response,
                'has_transaction_id' => isset($response['transaction_id'])
            ]);

            if (!$response || !isset($response['transaction_id'])) {
                Log::error('PagArmTrait::requestPaymentPagArm - Erro na resposta da API');
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao processar saque PagArm."
                    ]
                ];
            }

            // Criar solicitação de saque
            $solicitacaoCashOut = SolicitacoesCashOut::create([
                'user_id' => $user->username,
                'amount' => $request->amount,
                'taxa_cash_out' => $taxa_cash_out,
                'cashout_liquido' => $cashout_liquido,
                'idTransaction' => $response['transaction_id'],
                'external_id' => $externalId,
                'status' => 'PENDING',
                'adquirente' => 'pagarm',
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'date' => Carbon::now()
            ]);

            // Debitar valor do saldo do usuário
            Helper::decrementAmount($user, $valor_total_descontar, 'saldo');
            Helper::calculaSaldoLiquido($user->user_id);

            Log::info('PagArmTrait::requestPaymentPagArm - Saque processado:', [
                'solicitacao_id' => $solicitacaoCashOut->id,
                'transaction_id' => $response['transaction_id'],
                'valor_descontado' => $valor_total_descontar
            ]);

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Saque processado com sucesso",
                    "transaction_id" => $response['transaction_id'],
                    "external_id" => $externalId,
                    "amount" => $request->amount,
                    "taxa_cash_out" => $taxa_cash_out,
                    "cashout_liquido" => $cashout_liquido,
                    "solicitacao_id" => $solicitacaoCashOut->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('PagArmTrait::requestPaymentPagArm - Exceção:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro interno: " . $e->getMessage()
                ]
            ];
        }
    }
}