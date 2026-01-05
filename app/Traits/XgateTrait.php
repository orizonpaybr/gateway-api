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
use App\Services\XGate as XGateService;

trait XgateTrait
{

    public static function requestDepositXgate($request)
    {
        Log::info('XgateTrait::requestDepositXgate - INÍCIO', [
            'checkout_id' => $request->checkout_id ?? null,
            'metodo' => $request->metodo ?? null,
            'amount' => $request->amount,
            'all_data' => $request->all(),
            'has_checkout_id' => $request->has('checkout_id')
        ]);
        
        try {
            $xgateConfig = \App\Models\Xgate::first();
            if (!$xgateConfig || !$xgateConfig->status) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Xgate não configurado ou inativo."
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
                    Log::info('XgateTrait: Usuário obtido via checkout', [
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

            Log::info('=== XGATETRAIT REQUEST DEPOSIT INICIADO ===');
            Log::info('XgateTrait::requestDepositXgate - Dados da requisição:', [
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

            Log::info('XgateTrait::requestDepositXgate - Cálculo de taxas:', [
                'amount_original' => $valor,
                'taxa_cash_in' => $taxa_cash_in,
                'deposito_liquido' => $valor_liquido,
                'descricao' => $descricao_taxa
            ]);

            $date = Carbon::now();
            $descricao = "Depósito PIX via Xgate - R$ " . number_format($valor, 2, ',', '.');

            $xgate = new XGateService();
            $response = $xgate->genPayment($request);

            if (!isset($response['id'])) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao gerar pagamento Xgate"
                    ]
                ];
            }

            // Validar CPF/CNPJ antes de enviar para o Xgate
            $documentNumber = $request->debtor_document_number ?? $request->cpf ?? $user->cpf_cnpj ?? null;
            
            // Se não houver documento, gerar um CPF válido para teste
            if (!$documentNumber || $documentNumber === '00000000000') {
                $documentNumber = \App\Helpers\Helper::generateValidCpf();
                Log::info('XgateTrait: Gerando CPF válido para teste', ['cpf_gerado' => $documentNumber]);
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

            // Criar registro de solicitação
            $solicitacao = Solicitacoes::create([
                'user_id' => $user->user_id,
                'externalreference' => $response['id'],
                'amount' => $valor,
                'deposito_liquido' => $valor_liquido,
                'taxa_cash_in' => $taxa_cash_in,
                'taxa_pix_cash_in_adquirente' => 0,
                'taxa_pix_cash_in_valor_fixo' => 0,
                'client_name' => $request->debtor_name ?? $request->name ?? $user->name,
                'client_document' => $documentNumber,
                'client_email' => $request->email ?? $user->email,
                'client_telefone' => $request->phone ?? $request->telefone ?? $user->telefone ?? '11999999999',
                'executor_ordem' => 'xgate',
                'status' => 'WAITING_FOR_APPROVAL',
                'descricao_transacao' => $descricao_taxa,
                'idTransaction' => $response['id'],
                'qrcode_pix' => $response['code'] ?? null,
                'paymentcode' => $response['code'] ?? null,
                'paymentCodeBase64' => $response['code'] ?? null,
                'method' => 'PIX',
                'adquirente_ref' => 'xgate',
                'callback' => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/',
                'split_email' => $request->split_email ?? null,
                'split_percentage' => $request->split_percentage ?? null,
                'date' => $date,
                'created_at' => $date,
                'updated_at' => $date
            ]);

            Log::info('XgateTrait::requestDepositXgate - Registro de solicitação criado:', [
                'solicitacao_id' => $solicitacao->id,
                'externalreference' => $solicitacao->externalreference,
                'amount' => $solicitacao->amount,
                'deposito_liquido' => $solicitacao->deposito_liquido,
                'taxa_cash_in' => $solicitacao->taxa_cash_in
            ]);

            Log::info('=== XGATETRAIT REQUEST DEPOSIT FINALIZADO ===');

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Cobrança PIX criada com sucesso",
                    "idTransaction" => $response['id'],
                    "qrcode" => $response['code'] ?? null,
                    "qr_code_image_url" => 'https://quickchart.io/qr?text=' . urlencode($response['code'] ?? ''),
                    "charge" => [
                        "id" => $response['id'],
                        "value" => $valor,
                        "qrCode" => 'https://quickchart.io/qr?text=' . urlencode($response['code'] ?? ''),
                        "brCode" => $response['code'] ?? null,
                        "pixKey" => null,
                        "expiresAt" => null
                    ],
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no XgateTrait::requestDepositXgate: ' . $e->getMessage());
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro interno do servidor."
                ]
            ];
        }
    }

    public static function requestPaymentXgate($request)
    {
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

        if ($request->baasPostbackUrl === 'web') {
            if ($request->has('saque_automatico') && $request->saque_automatico) {
                // Processar saque automático diretamente via API
                return self::processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
            } else {
                // Processar como manual (criar solicitação para aprovação)
                return self::generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
            }
        }

        $xgate = new XGateService();
        $response = $xgate->genWithdraw($request);

        if (isset($response['message'])) {
            return [
                'status' => 401,
                'data' => ['message' => "Houve um erro. Tente novamente mais tarde."]
            ];
        }

        if (isset($response['status'])) {
            $name = $request->user()->name;

            $pixKey = $request->pixKey;

            switch ($request->pixKeyType) {
                case 'cpf':
                case 'cnpj':
                case 'phone':
                    $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                    break;
            }

            $pixcashout = [
                "user_id"               => $request->user()->username,
                "externalreference"     => $response['id'],
                "amount"                => $request->amount,
                "beneficiaryname"       => $name,
                "beneficiarydocument"   => $pixKey,
                "pix"                   => $pixKey,
                "pixkey"                => strtolower($request->pixKeyType),
                "date"                  => $date,
                "status"                => "PENDING",
                "type"                  => "PIX",
                "idTransaction"         => $response['id'],
                "taxa_cash_out"         => $taxa_cash_out,
                "cash_out_liquido"      => $cashout_liquido,
                "end_to_end"            => $response['id'],
                "callback"              => env('APP_URL') . '/callback/',
                "descricao_transacao"   => $descricao
            ];

            SolicitacoesCashOut::create($pixcashout);

            return [
                "status" => 200,
                "data" => [
                    "id"                => $response['id'],
                    "amount"            => $request->amount,
                    "pixKey"            => $request->pixKey,
                    "pixKeyType"        => $request->pixKeyType,
                    "withdrawStatusId"  => "PendingProcessing",
                    "createdAt"         => $date,
                    "updatedAt"         => $date
                ]
            ];
        }
    }

    protected static function generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        $idTransaction = Str::uuid()->toString();

        $name = $request->user()->name;
        $pixKey = $request->pixKey;

        switch ($request->pixKeyType) {
            case 'cpf':
            case 'cnpj':
            case 'phone':
                $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                break;
        }

        $pixcashout = [
            "user_id"               => $request->user()->username,
            "externalreference"     => $idTransaction,
            "amount"                => $request->amount,
            "beneficiaryname"       => $name,
            "beneficiarydocument"   => $pixKey,
            "pix"                   => $pixKey,
            "pixkey"                => strtolower($request->pixKeyType),
            "date"                  => $date,
            "status"                => "PENDING",
            "type"                  => $request->pixKeyType == "crypto" ? "CRYPTO" : "PIX",
            "idTransaction"         => $idTransaction,
            "taxa_cash_out"         => $taxa_cash_out,
            "cash_out_liquido"      => $cashout_liquido,
            "end_to_end"            => $idTransaction,
            "callback"              => $request->baasPostbackUrl,
            "blockchainNetwork"     => $request->blockchainNetwork ?? null,
            "cryptocurrency"        => $request->cryptocurrency ?? null,
            "descricao_transacao"   => "WEB"
        ];

        $cashout = SolicitacoesCashOut::create($pixcashout);

        return [
            "status" => 200,
            "data" => [
                "id"                => $idTransaction,
                "amount"            => $request->amount,
                "pixKey"            => $request->pixKey,
                "pixKeyType"        => $request->pixKeyType,
                "withdrawStatusId"  => "PendingProcessing",
                "createdAt"         => $date,
                "updatedAt"         => $date
            ]
        ];
    }

    public static function liberarSaqueManual($id)
    {

        $cashout = SolicitacoesCashOut::where('id', $id)->with('user')->first();
        $callback = url("cashtime/callback/withdraw");

        $xgate = new XGateService();
        if ($cashout->type == "CRYPTO") {
            $payload = [];
            $payload['amount'] = (float) $cashout->cash_out_liquido;
            $payload["blockchainNetwork"] = $cashout->blockchainNetwork;
            $payload["cryptocurrency"] = $cashout->cryptocurrency;
            $payload["wallet"] = $cashout->pix;

            $dt = [];
            $dt["user"] = $cashout->user;

            $response = $xgate->genWithdrawCrypto($payload, $dt);
            if (isset($response['message'])) {
                return back()->with('error', $response['message']);
            }


            $pixcashout = [
                "externalreference"     => $response['id'],
                "idTransaction"         => $response['id'],
                "end_to_end"            => $response['id'],
                "descricao_transacao"   => "LIBERADOADMIN"
            ];

            $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
            return back()->with('success', 'Pedido de saque enviado com sucesso!');
        }

        $request = [
            'amount' => $cashout->cash_out_liquido,
            'pixKeyType' => $cashout->pixkey,
            'pixKey' => $cashout->pix,
            'user' => $cashout->user
        ];
        $response = $xgate->genWithdraw($request);

        if (isset($response['message'])) {
            return back()->with('error', $response['message']);
        }


        $pixcashout = [
            "externalreference"     => $response['id'],
            "idTransaction"         => $response['id'],
            "end_to_end"            => $response['id'],
            "descricao_transacao"   => "LIBERADOADMIN"
        ];

        $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
        return back()->with('success', 'Pedido de saque enviado com sucesso!');
    }

    /**
     * Processa saque automático diretamente via API
     */
    protected static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        $withdrawData = [
            'amount' => $request->amount,
            'pix_key' => $request->pixKey,
            'pix_key_type' => $request->pixKeyType,
            'description' => "Saque automático - " . $request->user()->name,
            'beneficiary_name' => $request->user()->name,
            'beneficiary_document' => $request->pixKey
        ];

        $xgate = new XGateService();
        $response = $xgate->genWithdraw($withdrawData);

        if (isset($response['message'])) {
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => $response['message']
                ]
            ];
        }

        if (isset($response['id'])) {
            // Criar registro de saque automático
            $idTransaction = $response['id'];
            $name = $request->user()->name;
            $pixKey = $request->pixKey;

            switch ($request->pixKeyType) {
                case 'cpf':
                case 'cnpj':
                case 'phone':
                    $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                    break;
            }

            $pixcashout = [
                "user_id"               => $request->user()->username,
                "externalreference"     => $idTransaction,
                "amount"                => $request->amount,
                "beneficiaryname"       => $name,
                "beneficiarydocument"   => $pixKey,
                "pix"                   => $pixKey,
                "pixkey"                => strtolower($request->pixKeyType),
                "date"                  => $date,
                "status"                => "COMPLETED", // Status de completado para saque automático
                "type"                  => "PIX",
                "idTransaction"         => $idTransaction,
                "taxa_cash_out"         => $taxa_cash_out,
                "cash_out_liquido"      => $cashout_liquido,
                "end_to_end"            => $idTransaction,
                "callback"              => env('APP_URL') . '/callback/',
                "descricao_transacao"   => "AUTOMATICO"
            ];

            $cashout = SolicitacoesCashOut::create($pixcashout);

            // Atualizar saldo do usuário
            Helper::decrementAmount($user, $cashout_liquido, 'saldo');
            Helper::incrementAmount($user, $request->amount, 'valor_sacado');
            Helper::calculaSaldoLiquido($user->user_id);
            
            // Log específico para saque
            \App\Helpers\BalanceLogHelper::logSaqueOperation(
                'SAQUE_REQUEST',
                $user,
                $request->amount,
                [
                    'adquirente' => 'XGATE',
                    'valor_bruto' => $request->amount,
                    'valor_descontado' => $cashout_liquido,
                    'taxa_cash_out' => $taxa_cash_out,
                    'external_id' => $idTransaction,
                    'operacao' => 'processarSaqueAutomatico'
                ]
            );

            return [
                "status" => 200,
                "data" => [
                    "id"                => $idTransaction,
                    "amount"            => $request->amount,
                    "pixKey"            => $request->pixKey,
                    "pixKeyType"        => $request->pixKeyType,
                    "withdrawStatusId"  => "Completed",
                    "createdAt"         => $date,
                    "updatedAt"         => $date
                ]
            ];
        } else {
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro ao processar saque automático via API XGate."
                ]
            ];
        }
    }
}
