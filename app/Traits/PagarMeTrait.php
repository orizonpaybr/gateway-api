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
use App\Models\Pagarme;
use Faker\Factory as FakerFactory;
use App\Helpers\Helper;

trait PagarMeTrait
{
    protected static string $secret;
    protected static string $urlCashIn;
    protected static string $urlCashOut;
    protected static string $taxaCashIn;
    protected static string $taxaCashOut;

    protected static function generateCredentialsPagarme()
    {

        $setting = Pagarme::first();
        if (!$setting) {
            return false;
        }

        self::$secret = $setting->secret;
        self::$urlCashIn = $setting->url_cash_in;
        self::$urlCashOut = $setting->url_cash_out;
        self::$taxaCashIn = $setting->taxa_pix_cash_in;
        self::$taxaCashOut = $setting->taxa_pix_cash_out;

        return true;
    }

    public static function requestDepositPagarme($request)
    {

        if (self::generateCredentialsPagarme()) {
            $client_ip = $request->ip();

            $productid = uniqid();

            $document = $request->debtor_document;
            if (!Helper::validarCPF($request->debtor_document)) {
                $document = Helper::generateValidCpf();
            }

            $access_secret = base64_encode(self::$secret . ":");
            $gerarpessoa = self::gerarPessoa();
            $client_code = uniqid(strtoupper(str_replace(' ', '_', env('APP_NAME'))) . '_');

            $payload = [
                "customer" => [
                    "phones" => [
                        "mobile_phone" => [
                            "country_code" => "55", //código do pais
                            "area_code" => self::ajustePhone('' . $gerarpessoa['celular'])['ddd'], // código do estado
                            "number" => self::ajustePhone('' . $gerarpessoa['celular'])['phone'] //numero de celular
                        ]
                    ],
                    "name" => $gerarpessoa['nome'],
                    "document" =>  str_replace([".", "-"], "", $gerarpessoa['cpf']),
                    "email" => $gerarpessoa['email'],
                    "type" => "individual", // CPF: individual / CNPJ: company
                    "document_type" => "CPF" //"CPF", "CNPJ" ou "PASSPORT"
                ],
                "payments" => [
                    [
                        "Pix" => [
                            "expires_in" => 3600
                        ],
                        "payment_method" => "pix"
                    ]
                ],
                "items" => [
                    [
                        "amount" => intval($request['amount'] * 100), //em centavos
                        "code" => $client_code, //Gerar código unico
                        "quantity" => 1, // quantidade
                        "description" => "Pagamento $client_code" // descrição
                    ]
                ]
            ];


            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . $access_secret
            ])->post(self::$urlCashIn, $payload);
            //dd($response->json());
            if ($response->successful()) {

                $responseData = $response->json();
                $transaction_id = $responseData['id'];
                $setting = App::first();
                $user = $request->user;
                $taxafixa = $user->taxa_cash_in_fixa;


                $taxatotal = ((float)$request->amount * (float)$user->taxa_cash_in / 100);
                $deposito_liquido = (float)$request->amount - $taxatotal;
                $taxa_cash_in = $taxatotal;
                $descricao = "PORCENTAGEM";

                if ((float)$taxatotal < (float)$setting->baseline) {
                    $deposito_liquido = (float)$request->amount - (float)$setting->baseline;
                    $taxa_cash_in = (float)$setting->baseline;
                    $descricao = "FIXA";
                }


                $deposito_liquido = $deposito_liquido - $taxafixa;
                $taxa_cash_in = $taxa_cash_in + $taxafixa;


                $real_data = Carbon::now();

                $cashin = [
                    "user_id"                       => $request->user->username,
                    "externalreference"             => $transaction_id,
                    "amount"                        => $request->amount,
                    "client_name"                   => $request->debtor_name,
                    "client_document"               => $request->debtor_document_number,
                    "client_email"                  => $request->email,
                    "date"                          => $real_data,
                    "status"                        => 'WAITING_FOR_APPROVAL',
                    "idTransaction"                 => $transaction_id,
                    "deposito_liquido"              => $deposito_liquido,
                    "qrcode_pix"                    => $responseData['charges'][0]['last_transaction']['qr_code'],
                    "paymentcode"                   => $responseData['charges'][0]['last_transaction']['qr_code'],
                    "paymentCodeBase64"             => $responseData['charges'][0]['last_transaction']['qr_code'],
                    "adquirente_ref"                => 'Pagarme',
                    "taxa_cash_in"                  => $taxa_cash_in,
                    "taxa_pix_cash_in_adquirente"   => self::$taxaCashIn,
                    "taxa_pix_cash_in_valor_fixo"   => $taxafixa,
                    "client_telefone"               => $request->phone,
                    "executor_ordem"                => 'Pagarme',
                    "descricao_transacao"           => $descricao,
                    "callback"                      => $request->callbackUrl,
                    "split_email"                   => null,
                    "split_percentage"              => null
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
                        "status" => "success",
                        "message" => "ok",
                        "idTransaction" => $transaction_id,
                        "qrcode" => $responseData['charges'][0]['last_transaction']['qr_code'],
                        "qr_code_image_url" => $responseData['charges'][0]['last_transaction']['qr_code_url']
                    ],
                    "status" => 200
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

    /*  public static function requestPaymentPagarme($request)
    {
        $user = User::where('id', $request->user()->id)->first();
        $keypix = $request->keypix;
        $name = $request->user()->name;

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

        $real_data = Carbon::now();

        if($request->baasPostbackUrl === 'web'){
            if ($request->has('saque_automatico') && $request->saque_automatico) {
                // Processar saque automático diretamente via API
                return self::processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $real_data, $descricao, $user);
            } else {
                // Processar como manual (criar solicitação para aprovação)
                return self::generateTransactionPaymentManualPagarme($request, $taxa_cash_out, $cashout_liquido, $real_data, $descricao, $user);
            }
        }

        if(self::generateCredentialsPagarme()){
            $callback = url("Pagarme/callback/withdraw");
            $client_ip = \App\Traits\IPManagementTrait::getIPForAcquirer($request);
            
            $keytype = $request->keytype;
            if(isset($request->keytype)){
                $keytype = $request->keytype;
            } else {
                $keytype = Helper::verifyPixType($keypix);
            }

            $payload = [
                "amount"            => intval($request->amount * 100),
                "pixKey"            => $request->keypix,
                "pixKeyType"        => $keytype,
                "baasPostbackUrl"   => $callback
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-authorization-key' => self::$secret
            ])->post(self::$urlCashOut, $payload);
            Log::debug("Resposta solicitacao saque Body: ".json_encode($response->json())); 
            if($response->successful()){
                $responseData = $response->json();
              	$pixKey = $request->keypix;

                switch ($keytype) {
                    case 'cpf':
                    case 'cnpj':
                    case 'phone':
                        $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                        break;
                }

                $pixcashout = [
                    "user_id"               => $request->user()->username, 
                    "externalreference"     => $responseData['id'], 
                    "amount"                => $request['amount'], 
                    "beneficiaryname"       => $name, 
                    "beneficiarydocument"   => $keypix, 
                    "pix"                   => $keypix, 
                    "pixkey"                => $keytype, 
                    "date"                  => $real_data, 
                    "status"                => "PENDING", 
                    "type"                  => "PIX", 
                    "idTransaction"         => $responseData['id'], 
                    "taxa_cash_out"         => $taxa_cash_out, 
                    "cash_out_liquido"      => $cashout_liquido, 
                    "end_to_end"            => $responseData['id'],
                    "callback"              => $request->callbackUrl,
                    "descricao_transacao"   => $descricao 
                ];

                $cashout = SolicitacoesCashOut::create($pixcashout);

                return [
                    "status" => 200,
                    "data" => [
                        "idTransaction"     => $responseData['id'],
                        "status"            => "processing"
                    ]
                ];
            } else {
                return [
                    "status" => 400,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao processar o saque... Tente novamente mais tarde."
                    ]
                ];
            }
        } else {
            return [
                "status" => 200,
                "data" => [
                    "status" => "error"
                ]
            ];
        }
    }

    protected static function generateTransactionPaymentManualPagarme($request, $taxa_cash_out, $cashout_liquido, $real_data, $descricao, $user)
    {
        $idTransaction = Str::uuid()->toString();
      	$keypix = $request->keypix;
        $pixKey = $request->pixKey;

        $keytype = $request->keytype;
        if(isset($request->keytype)){
            $keytype = $request->keytype;
        } else {
            $keytype = Helper::verifyPixType($keypix);
            if($keytype === 'invalid'){
                return response()->json(['status' => 'error', 'message' => 'Chave PIX Inválida.']);
            }
        }

        switch ($keypix) {
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
            "beneficiaryname"       => $request->name, 
            "beneficiarydocument"   => $request->cpf, 
            "pix"                   => $pixKey, 
            "pixkey"                => $keytype, 
            "date"                  => $real_data, 
            "status"                => "PENDING", 
            "type"                  => "PIX", 
            "idTransaction"         => $idTransaction, 
            "taxa_cash_out"         => $taxa_cash_out, 
            "cash_out_liquido"      => $cashout_liquido, 
            "end_to_end"            => $idTransaction,
            "callback"              => $request->callbackUrl,
            "descricao_transacao"   => "WEB"
        ];

        $cashout = SolicitacoesCashOut::create($pixcashout);

        return [
            "status" => 200,
            "data" => [
                "idTransaction"     => $idTransaction,
                "status"            => "processing"
            ]
        ];
    }

    public static function liberarSaqueManualPagarme($id)
    {
        if(self::generateCredentialsPagarme()){
            $cashout = SolicitacoesCashOut::where('id', $id)->first();
            $callback = url("Pagarme/callback/withdraw");

            $payload = [
                "amount"            => floatval($cashout->amount * 100),
                "pixKey"            => $cashout->pix,
                "pixKeyType"        => $cashout->pixkey,
                "baasPostbackUrl"   => $callback
            ];
        
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-authorization-key' => self::$secret,
                'x-store-key' => self::$secret,
            ])->post(self::$urlCashOut, $payload);
    
            
            if($response->successful()){
                $responseData = $response->json();                
                $pixcashout = [
                    "externalreference"     => $responseData['id'],
                    "idTransaction"         => $responseData['id'],
                    "end_to_end"            => $responseData['id'],
                    "descricao_transacao"   => "LIBERADOADMIN" 
                ];

                $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
                return back()->with('success', 'Pedido de saque enviado com sucesso!');
            } else {
                return back()->with('error', 'Houve um erro ao liberar saque.');
            }

        }
    } */

    protected static function ajustePhone($string)
    {
        // Verifica se "55" aparece antes de "62"
        if (strpos($string, "55") === 0 && strpos($string, "62") === 2) {
            // Pega os dois primeiros números (62) como DDD
            $ddd = substr($string, 2, 2); // Pega "62"
            // Remove o "55" e pega o restante do número
            $phone = substr($string, 4); // Pega "981313984"
        } else {
            // Se não tiver o 55 antes do 62, apenas divide normalmente
            $ddd = substr($string, 0, 2); // Pega os dois primeiros números (DDD)
            $phone = substr($string, 2);   // Pega o restante do número
        }

        return [
            'ddd' => $ddd,
            'phone' => $phone
        ];
    }

    public static function gerarPessoa()
    {
        $url = "https://www.4devs.com.br/ferramentas_online.php";
        $request = "acao=gerar_pessoa&sexo=I&pontuacao=N&idade=0&cep_estado=&txt_qtde=1&cep_cidade=";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/x-www-form-urlencoded",
                "Referer: https://www.4devs.com.br/gerador_de_pessoas",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0",
            ],
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        if ($response) {
            $dados = json_decode($response, true);
            if (isset($dados[0]['nome']) && isset($dados[0]['cpf']) && isset($dados[0]['email'])) {
                return $dados[0]; // Retorna o primeiro registro do JSON
            }
        }

        return null; // Falha ao gerar os dados
    }

    /**
     * Processa saque automático diretamente via API
     */
    protected static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $real_data, $descricao, $user)
    {
        if(self::generateCredentialsPagarme()){
            $callback = url("Pagarme/callback/withdraw");
            $client_ip = \App\Traits\IPManagementTrait::getIPForAcquirer($request);
            
            $keytype = $request->keytype;
            $pixKey = $request->pixKey;

            switch ($keytype) {
                case 'cpf':
                case 'cnpj':
                case 'phone':
                    $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                    break;
            }

            $payload = [
                "amount"            => floatval($request->amount * 100),
                "pixKey"            => $pixKey,
                "pixKeyType"        => $keytype,
                "baasPostbackUrl"   => $callback,
                "client_ip"         => $client_ip
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . self::$accessToken,
            ])->post(self::$urlPixOut, $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Criar registro de saque automático
                $idTransaction = $responseData['id'] ?? Str::uuid()->toString();
                $name = $request->user()->name;

                $pixcashout = [
                    "user_id"               => $request->user()->username,
                    "externalreference"     => $idTransaction,
                    "amount"                => $request->amount,
                    "beneficiaryname"       => $name,
                    "beneficiarydocument"   => $pixKey,
                    "pix"                   => $pixKey,
                    "pixkey"                => strtolower($keytype),
                    "date"                  => $real_data,
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
                        "pixKeyType"        => $keytype,
                        "withdrawStatusId"  => "Completed",
                        "createdAt"         => $real_data,
                        "updatedAt"         => $real_data
                    ]
                ];
            } else {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao processar saque automático via API PagarMe."
                    ]
                ];
            }
        } else {
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Credenciais de API não configuradas."
                ]
            ];
        }
    }
}
