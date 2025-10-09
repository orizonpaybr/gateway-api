<?php

namespace App\traits;

use App\Helpers\Helper;
use App\Models\AdMercadopago;
use App\Models\App;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Traits\UtmfyTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait MercadoPagoTrait
{
    protected static string $access_token;
    protected static string $taxa_cashIn;

    protected static function generateCredentialsMercadoPago()
    {
        $setting = AdMercadopago::first();
        self::$access_token = $setting->access_token;
        self::$taxa_cashIn = $setting->taxa_pix_cash_in;

        return true;
    }


    public static function requestDepositMercadopago($request)
    {
        if (self::generateCredentialsMercadoPago()) {
            $client_ip = \App\Traits\IPManagementTrait::getIPForAcquirer($request);

            $document = Helper::generateValidCpf();
            $valor = $request->amount;

            $stringGenerate = Str::uuid();
            $token = Helper::MakeToken([
                'total' => $valor,
                'qty' => 1,
                'user_id' => $stringGenerate
            ]);

            $pessoa = Helper::gerarPessoa();
            $name = explode(' ', $pessoa['nome'])[0];
            $lastname = explode(' ', $pessoa['nome'])[1];
            $cpf = $pessoa['cpf'];
            $email = $pessoa['email'];

            $response = Http::withHeaders([
                'X-Idempotency-Key' => $token,
                'Authorization' => 'Bearer ' . self::$access_token,
            ])->post('https://api.mercadopago.com/v1/payments', [
                "transaction_amount" => floatval($valor),
                "description" => 'Pagamento',
                "payment_method_id" => "pix",
                "notification_url" => url('mercadopago/callback/deposit'),
                "external_reference" => $stringGenerate,
                "payer" => [
                    "email" => $email,
                    "first_name" => $name,
                    "last_name" => $lastname,
                    "identification" => [
                        "type" => "CPF",
                        "number" => Helper::soNumero($cpf)
                    ]
                ]
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $transactionData = $responseData['point_of_interaction']['transaction_data'];
                //dd($transactionData);

                $setting = App::first();
                $user = $request->user;

                // Calcula taxas usando o sistema flexível
                $taxaCalculada = \App\Helpers\TaxaFlexivelHelper::calcularTaxaDeposito($request->amount, $setting, $user);
                $deposito_liquido = $taxaCalculada['deposito_liquido'];
                $taxa_cash_in = $taxaCalculada['taxa_cash_in'];
                $descricao = $taxaCalculada['descricao'];

                $date = Carbon::now();

                $cashin = [
                    "user_id"                       => $request->user->username,
                    "externalreference"             => $responseData['external_reference'],
                    "amount"                        => $request->amount,
                    "client_name"                   => $request->debtor_name,
                    "client_document"               => $document,
                    "client_email"                  => $request->email,
                    "date"                             => $date,
                    "status"                        => 'WAITING_FOR_APPROVAL',
                    "idTransaction"                 => $responseData['external_reference'],
                    "deposito_liquido"              => $deposito_liquido,
                    "qrcode_pix"                    => $transactionData['qr_code'],
                    "paymentcode"                   => $transactionData['qr_code'],
                    "paymentCodeBase64"             => $transactionData['qr_code'],
                    "adquirente_ref"                => 'mercadopago',
                    "taxa_cash_in"                  => $taxa_cash_in,
                    "taxa_pix_cash_in_adquirente"   => self::$taxa_cashIn,
                    "taxa_pix_cash_in_valor_fixo"   => 0,
                    "client_telefone"               => $request->phone,
                    "executor_ordem"                => 'mercadopago',
                    "descricao_transacao"           => $descricao,
                    "callback"                      => env('APP_URL') . '/callback/',
                    "split_email"                   => null,
                    "split_percentage"              => null,
                ];

                Solicitacoes::create($cashin);

                if (!is_null($user->integracao_utmfy)) {

                    $ip = $request->header('X-Forwarded-For') ?
                        $request->header('X-Forwarded-For') : ($request->header('CF-Connecting-IP') ?
                            $request->header('CF-Connecting-IP') :
                            $request->ip());
                    $msg = "PIX Gerado " . env('APP_NAME');
                    UtmfyTrait::gerarUTM('pix', 'waiting_payment', $cashin, $user->integracao_utmfy, $ip, $msg);
                }

                return [
                    "data" => [
                        "idTransaction" => $responseData['external_reference'],
                        "qrcode" => $transactionData['qr_code'],
                        "qr_code_image_url" => 'https://quickchart.io/qr?text=' . $transactionData['qr_code']
                    ],
                    "status" => 200
                ];
            } else {
                $responseData = $response->json();
                return [
                    "data" => [
                        'status' => 'error',
                        'message' => $responseData['message'] ?? "Houve um erro. tente novamente."
                    ],
                    "status" => 401
                ];
            }
        } else {
            return [
                "data" => [
                    'status' => 'error'
                ],
                "status" => 401
            ];
        }
    }

    public static function requestPaymentCashtime($request)
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
            return response()->json([
                'status' => 'error',
                'message' => "Saldo insuficiente. Necessário: R$ " . number_format($saldo_necessario, 2, ',', '.') . ", Disponível: R$ " . number_format($user->saldo, 2, ',', '.'),
            ], 401);
        }

        $date = Carbon::now();
        
        // Verificar se é saque automático
        if ($request->has('saque_automatico') && $request->saque_automatico) {
            // Processar saque automático diretamente via API
            return self::processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
        } else {
            // Processar como manual (criar solicitação para aprovação)
            return self::generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
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
            "type"                  => "PIX",
            "idTransaction"         => $idTransaction,
            "taxa_cash_out"         => $taxa_cash_out,
            "cash_out_liquido"      => $cashout_liquido,
            "end_to_end"            => $idTransaction,
            "callback"              => env('APP_URL') . '/callback/',
            "descricao_transacao"   => "WEB"
        ];

        SolicitacoesCashOut::create($pixcashout);

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
        $pixcashout = [
            "status"                => "COMPLETED",
            "descricao_transacao"   => "LIBERADOADMIN"
        ];

        SolicitacoesCashOut::where('id', $id)->update($pixcashout);
        return back()->with('success', "Saque atualizado para 'PAGO' com sucesso!");
    }

    /**
     * Processa saque automático diretamente via API
     */
    protected static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        try {
            // Para MercadoPago, vamos simular o processamento automático
            // já que não temos uma API específica de saque PIX
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
        } catch (\Exception $e) {
            \Log::error('Erro no MercadoPagoTrait::processarSaqueAutomatico: ' . $e->getMessage());
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro interno ao processar saque automático."
                ]
            ];
        }
    }
}
