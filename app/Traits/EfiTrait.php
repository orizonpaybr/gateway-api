<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Helpers\SecureHttp;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\App;
use App\Models\User;
use App\Models\Efi;
use App\Helpers\Helper;
use App\Models\CheckoutBuild;
use App\Models\CheckoutOrders;
use App\Models\Transactions;
use Illuminate\Support\Facades\Request;
use Efi\Exception\EfiException;
use Efi\EfiPay;

trait EfiTrait
{
    protected static string $baseUrl;
    protected static string $access_token;
    protected static string $chave_pix;
    protected static string $client_id;
    protected static string $client_secret;
    protected static string $cert;
    protected static string $urlCashIn;
    protected static string $urlCashOut;
    protected static string $taxaCashIn;
    protected static string $taxaCashOut;
    protected static string $billetTxFixed;
    protected static string $billetTxPercent;
    protected static string $env;

    protected static function generateCredentials()
    {

        $setting = Efi::first();
        if (!$setting) {
            return false;
        }

        if (env('EFI_ENV') == "development") {
            self::$baseUrl = "https://pix-h.api.efipay.com.br";
        } else {
            self::$baseUrl = "https://pix.api.efipay.com.br";
        }
        self::$chave_pix = $setting->chave_pix ?? '';
        self::$client_id = $setting->client_id ?? '';
        self::$client_secret = $setting->client_secret ?? '';
        self::$cert = storage_path('app/private/certificados/producao.pem');
        self::$taxaCashIn = $setting->taxa_pix_cash_in ?? '0';
        self::$taxaCashOut = $setting->taxa_pix_cash_out ?? '0';
        self::$env = env('EFI_ENV');

        $type = 'api';
        if ($type == 'api') {
            $endpoint = self::$baseUrl . '/oauth/token';
        } else {
            $endpoint = self::$baseUrl . '/v1/authorize';
        }

        $certPath = storage_path('app/private/certificados/producao.pem');

        // Verificação do certificado
        if (!file_exists($certPath)) {
            throw new \RuntimeException("Certificado não encontrado em: $certPath");
        }

        $payload = [
            "grant_type" => "client_credentials"
        ];
        $autorizacao =  base64_encode(self::$client_id . ":" . self::$client_secret);
        // Fazer a requisição
        $response = SecureHttp::postWithCert(
            $endpoint,
            $payload,
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . $autorizacao,
            ],
            $certPath,
            ''
        );

        // Retornar a resposta formatada
        if ($response->successful()) {
            $res = $response->json();
            if (isset($res['access_token'])) {
                self::$access_token = $res['access_token'];
                return true;
            }
        }

        // Se a resposta não for bem-sucedida ou não contiver o token, registrar o erro.
        Log::error('Falha na autenticação com a API Efí.', [
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body()
        ]);

        return false;
    }

    public static function generateAccessToken()
    {
        $setting = Efi::first();
        if (!$setting) {
            return false;
        }
        self::$chave_pix = $setting->chave_pix ?? '';
        self::$client_id = $setting->client_id ?? '';
        self::$client_secret = $setting->client_secret ?? '';
        self::$billetTxFixed = $setting->billet_tx_fixed ?? '0';
        self::$billetTxPercent = $setting->billet_tx_percent ?? '0';
        self::$cert = storage_path('app/private/certificados/producao.pem');
        self::$env = env('EFI_ENV');

        $autorizacao = base64_encode(self::$client_id . ':' . self::$client_secret);

        $endpoint = "";
        switch (self::$env) {
            case 'production':
                $endpoint = 'https://cobrancas.api.efipay.com.br/v1/authorize';
                self::$baseUrl = "https://cobrancas.api.efipay.com.br";
                break;
            case 'development':
            default:
                $endpoint = 'https://cobrancas-h.api.efipay.com.br/v1/authorize';
                self::$baseUrl = "https://cobrancas-h.api.efipay.com.br";
                break;
        }

        $response = Http::withHeaders(['Authorization' => "Basic .$autorizacao", 'Content-Type' => "application/json"])
            ->post($endpoint, ['grant_type' => 'client_credentials']);

        return $response->json()['access_token'];
    }

    public static function requestDepositEfi($request)
    {
        Log::info('🔍 EfiTrait::requestDepositEfi - INÍCIO', [
            'checkout_id' => $request->checkout_id ?? null,
            'metodo' => $request->metodo ?? null,
            'amount' => $request->amount,
            'all_data' => $request->all(),
            'has_checkout_id' => $request->has('checkout_id')
        ]);
        
        try {
            $efi = \App\Models\Efi::first();
            if (!$efi || !$efi->status) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "EFI não configurado ou inativo."
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
                    Log::info('🔍 EfiTrait: Usuário obtido via checkout', [
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

            Log::info('=== EFITRAIT REQUEST DEPOSIT INICIADO ===');
            Log::info('EfiTrait::requestDepositEfi - Dados da requisição:', [
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

            Log::info('EfiTrait::requestDepositEfi - Cálculo de taxas:', [
                'amount_original' => $valor,
                'taxa_cash_in' => $taxa_cash_in,
                'deposito_liquido' => $valor_liquido,
                'descricao' => $descricao_taxa
            ]);

            $date = Carbon::now();
            $descricao = "Depósito PIX via EFI - R$ " . number_format($valor, 2, ',', '.');

            if (!self::generateCredentials()) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao gerar credenciais EFI."
                    ]
                ];
            }

            // Validar CPF/CNPJ antes de enviar para o EFI
            $documentNumber = $request->debtor_document_number ?? $request->cpf ?? $user->cpf_cnpj ?? null;
            
            // Se não houver documento, gerar um CPF válido para teste
            if (!$documentNumber || $documentNumber === '00000000000') {
                $documentNumber = Helper::generateValidCpf();
                Log::info('EfiTrait: Gerando CPF válido para teste', ['cpf_gerado' => $documentNumber]);
            } else {
                $cleanDocument = preg_replace('/\D/', '', $documentNumber);
                
                // Verificar se é um CPF (11 dígitos) - válido ou inválido
                if (strlen($cleanDocument) === 11) {
                    if (!Helper::validarCPF($documentNumber)) {
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
                    if (!Helper::validarCNPJ($documentNumber)) {
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

            $cpfLimpo = preg_replace('/[^0-9]/', '', $documentNumber);

            // Dados para criar cobrança EFI
            $payload = [
                "calendario" => ["expiracao" => 3600],
                "devedor" => [
                    "cpf" => $cpfLimpo,
                    "nome" => $request->debtor_name ?? $request->name ?? $user->name
                ],
                "valor" => ["original" => number_format($valor, 2, '.', '')],
                "chave" => self::$chave_pix,
                "solicitacaoPagador" => $descricao
            ];

            // Fazer a requisição
            $response = Http::withOptions([
                'cert' => [self::$cert, ''],
                'verify' => false
            ])
                ->withHeaders([
                    'authorization' => 'Bearer ' . self::$access_token,
                    'Content-Type' => 'application/json'
                ])
                ->post(self::$baseUrl . '/v2/cob', $payload);

            if (!$response->successful()) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao criar cobrança EFI: " . $response->body()
                    ]
                ];
            }

            $responseData = $response->json();

            // Criar registro de solicitação
            $solicitacao = Solicitacoes::create([
                'user_id' => $user->user_id,
                'externalreference' => $responseData['txid'] ?? uniqid(),
                'amount' => $valor,
                'deposito_liquido' => $valor_liquido,
                'taxa_cash_in' => $taxa_cash_in,
                'taxa_pix_cash_in_adquirente' => self::$taxaCashIn ?? 0,
                'taxa_pix_cash_in_valor_fixo' => 0,
                'client_name' => $request->debtor_name ?? $request->name ?? $user->name,
                'client_document' => $documentNumber,
                'client_email' => $request->email ?? $user->email,
                'client_telefone' => $request->phone ?? $request->telefone ?? $user->telefone ?? '00000000000',
                'executor_ordem' => 'efi',
                'status' => 'WAITING_FOR_APPROVAL',
                'descricao_transacao' => $descricao_taxa,
                'idTransaction' => $responseData['txid'] ?? uniqid(),
                'qrcode_pix' => $responseData['pixCopiaECola'] ?? null,
                'paymentcode' => $responseData['pixCopiaECola'] ?? null,
                'paymentCodeBase64' => $responseData['pixCopiaECola'] ?? null,
                'method' => 'PIX',
                'adquirente_ref' => 'efi',
                'callback' => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/',
                'split_email' => $request->split_email ?? null,
                'split_percentage' => $request->split_percentage ?? null,
                'date' => $date,
                'created_at' => $date,
                'updated_at' => $date
            ]);

            Log::info('EfiTrait::requestDepositEfi - Registro de solicitação criado:', [
                'solicitacao_id' => $solicitacao->id,
                'externalreference' => $solicitacao->externalreference,
                'amount' => $solicitacao->amount,
                'deposito_liquido' => $solicitacao->deposito_liquido,
                'taxa_cash_in' => $solicitacao->taxa_cash_in
            ]);

            // UTMfy integration
            if (!is_null($user->integracao_utmfy)) {
                $ip = $request->header('X-Forwarded-For') ?
                    $request->header('X-Forwarded-For') : ($request->header('CF-Connecting-IP') ?
                        $request->header('CF-Connecting-IP') :
                        $request->ip());

                $msg = "PIX Gerado " . env('APP_NAME');
                \App\Traits\UtmfyTrait::gerarUTM('pix', 'waiting_payment', $solicitacao->toArray(), $user->integracao_utmfy, $ip, $msg);
            }

            Log::info('=== EFITRAIT REQUEST DEPOSIT FINALIZADO ===');

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Cobrança PIX criada com sucesso",
                    "idTransaction" => $responseData['txid'],
                    "qrcode" => $responseData['pixCopiaECola'] ?? null,
                    "qr_code_image_url" => 'https://quickchart.io/qr?text=' . ($responseData['pixCopiaECola'] ?? ''),
                    "charge" => [
                        "id" => $responseData['txid'],
                        "value" => $valor,
                        "qrCode" => 'https://quickchart.io/qr?text=' . ($responseData['pixCopiaECola'] ?? ''),
                        "brCode" => $responseData['pixCopiaECola'] ?? null,
                        "pixKey" => self::$chave_pix ?? null,
                        "expiresAt" => null
                    ],
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no EfiTrait::requestDepositEfi: ' . $e->getMessage());
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro interno do servidor."
                ]
            ];
        }
    }

    public static function requestPaymentEfi($request)
    {
        $user = User::where('id', $request->user()->id)->first();

        $setting = App::first();

        $user = $request->user;
        
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

        if ($cashout_liquido < $taxa_cash_out) {
            $valor = "R$ " . number_format($taxa_cash_out, 2, ',', '.');
            return response()->json([
                'status' => 'error',
                'message' => "Valor solicitado menor que as taxas. Solicite um valor acima de $valor.",
            ], 401);
        }

        $date = Carbon::now();

        if ($request->baasPostbackUrl === 'web') {
            // Verificar se é saque automático
            if ($request->has('saque_automatico') && $request->saque_automatico) {
                // Processar saque automático diretamente via API
                return self::processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
            } else {
                // Processar como manual (criar solicitação para aprovação)
                return self::generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
            }
        }

        if (self::generateCredentials()) {

            $payload = [
                "valor" => number_format($request->amount, '2', '.', ','),
                "pagador" => [
                    "chave" => self::$chave_pix,
                    "infoPagador" => "Segue o pagamento da conta"
                ],
                "favorecido" => [
                    "chave" => $request->pixKey
                ]
            ];

            $internal_id = str_replace('-', '', Str::uuid()->toString());

            // Fazer a requisição
            $response = Http::withOptions([
                'cert' => [self::$cert, ''],
                'verify' => true // ← Adicionado aqui
            ])
                ->withHeaders([
                    'authorization' => 'Bearer ' . self::$access_token,
                    'Content-Type' => 'application/json'
                ])
                ->put(self::$baseUrl . '/v3/gn/pix/' . $internal_id, $payload);


            if ($response->successful()) {
                //Helper::incrementAmount($user, $request->amount, 'valor_saque_pendente');
                //Helper::decrementAmount($user, $cashout_liquido, 'saldo');

                $name = $request->user()->name;
                $responseData = $response->json();

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
                    "externalreference"     => $responseData['idEnvio'],
                    "amount"                => $request->amount,
                    "beneficiaryname"       => $name,
                    "beneficiarydocument"   => $pixKey,
                    "pix"                   => $pixKey,
                    "pixkey"                => strtolower($request->pixKeyType),
                    "date"                  => $date,
                    "status"                => "PENDING",
                    "type"                  => "PIX",
                    "idTransaction"         => $responseData['idEnvio'],
                    "taxa_cash_out"         => $taxa_cash_out,
                    "cash_out_liquido"      => $cashout_liquido,
                    "end_to_end"            => $responseData['e2eId'],
                    "callback"              => env('APP_URL') . '/callback/',
                    "descricao_transacao"   => $descricao
                ];

                $cashout = SolicitacoesCashOut::create($pixcashout);

                return [
                    "status" => 200,
                    "data" => [
                        "id"                => $responseData['idEnvio'],
                        "amount"            => $request->amount,
                        "pixKey"            => $request->pixKey,
                        "pixKeyType"        => $request->pixKeyType,
                        "withdrawStatusId"  => $responseData["PendingProcessing"] ?? "PendingProcessing",
                        "createdAt"         => $responseData['createdAt'] ?? $date,
                        "updatedAt"         => $responseData['updatedAt'] ?? $date
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
            "callback"              => $request->baasPostbackUrl,
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
        if (self::generateCredentials()) {
            $cashout = SolicitacoesCashOut::where('id', $id)->first();
            $callback = url("Efi/callback/withdraw");

            $payload = [
                "valor" => number_format($cashout->cash_out_liquido, '2', '.', ','),
                "pagador" => [
                    "chave" => self::$chave_pix,
                    "infoPagador" => "Segue o pagamento da conta"
                ],
                "favorecido" => [
                    "chave" => $cashout->pix
                ]
            ];

            $internal_id = str_replace('-', '', Str::uuid()->toString());

            // Fazer a requisição
            $response = Http::withOptions([
                'cert' => [self::$cert, ''],
                'verify' => true // ← Adicionado aqui
            ])
                ->withHeaders([
                    'authorization' => 'Bearer ' . self::$access_token,
                    'Content-Type' => 'application/json'
                ])
                ->put(self::$baseUrl . '/v3/gn/pix/' . $internal_id, $payload);

            Log::debug('RESPOSTA LIBERAR SAQUE: ' . json_encode($response->json()));
            if ($response->successful()) {
                $responseData = $response->json();
                $pixcashout = [
                    "externalreference"     => $responseData['idEnvio'],
                    "idTransaction"         => $responseData['idEnvio'],
                    "end_to_end"            => $responseData['e2eId'],
                    "descricao_transacao"   => "LIBERADOADMIN"
                ];

                $cashout = SolicitacoesCashOut::where('id', $id)->update($pixcashout);
                return back()->with('success', 'Pedido de saque enviado com sucesso!');
            } else {
                $responseData = $response->json();

                return back()->with('error', 'Houve um erro ao liberar saque.');
            }
        }
    }

    public static function cadastrarWebhook()
    {
        if (self::generateCredentials()) {

            $access_token = self::$access_token;
            $url = env('APP_URL') . '/efi/callback?ignorar=';
            $chave = self::$chave_pix;

            $certPath = storage_path('app/private/certificados/producao.pem');

            $payload = [
                "webhookUrl" => $url,
            ];

            // Fazer a requisição
            $response = Http::withOptions([
                'cert' => [$certPath, ''],
                'verify' => false // ← Adicionado aqui
            ])
                ->withHeaders([
                    'authorization' => 'Bearer ' . $access_token,
                    'x-skip-mtls-checking' => "true",
                    'Content-Type' => 'application/json'
                ])
                ->put(self::$baseUrl . '/v2/webhook/' . $chave, $payload);

            // Retornar a resposta formatada
            $res = $response->json();
            //dd($res);
            return $res;
        }
    }

    public static function requestBoletoEfi($request)
    {
        $access_token = self::generateAccessToken();
        $request = $request->all();

        $external_id = uniqid('BILLET_');

        $request['payment']['banking_billet']['customer']['cpf'] = str_replace(['.', '-', '(', ')', ' '], '', $request['payment']['banking_billet']['customer']['cpf']);
        $request['payment']['banking_billet']['customer']['phone_number'] = str_replace(['.', '-', '(', ')', ' '], '', $request['payment']['banking_billet']['customer']['phone_number']);
        if (isset($request['payment']['banking_billet']['customer']['address']['zipcode'])) {

            $request['payment']['banking_billet']['customer']['address']['zipcode'] = str_replace(['.', '-', '(', ')', ' '], '', $request['payment']['banking_billet']['customer']['address']['zipcode']);
        }
        $request['payment']['banking_billet']['expire_at'] = date('Y-m-d');
        $request['metadata']['notification_url'] = url('efi/billet/notification');
        $request['metadata']['custom_id'] = $external_id;

        unset($request['user'], $request['token'], $request['secret']);
        Log::debug('[+][EFI][REQUESTBILLET][PAYLOAD]: ' . json_encode($request));
        $response = Http::withHeaders([
            'Authorization' => "Bearer $access_token",
            'Content-Type' => "application/json",
            'cert' => [self::$cert, ''],
            'verify' => false
        ])
            ->post(self::$baseUrl . "/v1/charge/one-step", $request);


        Log::debug('[+][EFI][REQUESTBILLET][RESPONSE][BODY]: ' . $response->body());

        if ($response->successful()) {

            $responseData = $response->json();
            $responseData = $response->json()['data'];
            $responseData['qrcode'] = $responseData['pix']['qrcode'];
            $responseData['qrcode_image'] = $responseData['pix']['qrcode'];
            $responseData['download'] = $responseData['pdf']['charge'];
            unset($responseData['link'], $responseData['billet_link'], $responseData['charge_id'], $responseData['payment'], $responseData['pdf'], $responseData['pix']);
            $responseData['status'] = 'WAITING_FOR_APPROVAL';
            $amount = (float) $request['items'][0]['value'] / 100;
            $setting = App::first();
            $efi = Efi::first();
            $taxafixa = self::$billetTxFixed;


            $taxatotal = ((float) $amount * (float) self::$billetTxPercent / 100);
            $deposito_liquido = (float) $amount - $taxatotal;
            $taxa_cash_in = $taxatotal;
            $descricao = "PORCENTAGEM";

            if ((float)$taxatotal < (float)$setting->baseline) {
                $deposito_liquido = (float) $amount - (float)$setting->baseline;
                $taxa_cash_in = (float)$setting->baseline;
                $descricao = "FIXA";
            }


            $deposito_liquido = $deposito_liquido - $taxafixa;
            $taxa_cash_in = $taxa_cash_in + $taxafixa;

            Log::debug("[+][EFI][REQUESTCARD][TAXA E VALORES]: [amount: $amount, liquido: $deposito_liquido, taxas: $taxa_cash_in]");
            $date = Carbon::now();

            $days_availability = $efi->billet_days_availability;
            $cashin = [
                "method"                        => "billet",
                "barcode"                       => $response->json()['data']['barcode'],
                "user_id"                       => $request->user()->username,
                "externalreference"             => $response->json()['data']['charge_id'],
                "amount"                        => $amount,
                "client_name"                   => $request['payment']['banking_billet']['customer']['name'],
                "client_document"               => $request['payment']['banking_billet']['customer']['cpf'],
                "client_email"                  => $request['payment']['banking_billet']['customer']['email'],
                "date"                          => $date,
                "status"                        => 'WAITING_FOR_APPROVAL',
                "idTransaction"                 => $external_id,
                "deposito_liquido"              => $deposito_liquido,
                "qrcode_pix"                    => $responseData['qrcode'],
                "paymentcode"                   => $responseData['qrcode'],
                "paymentCodeBase64"             => $responseData['qrcode'],
                "billet_download"               => $responseData['download'],
                "adquirente_ref"                => 'Efi',
                "taxa_cash_in"                  => $taxa_cash_in,
                "taxa_pix_cash_in_adquirente"   => self::$billetTxPercent,
                "taxa_pix_cash_in_valor_fixo"   => $taxafixa,
                "client_telefone"               => $request['payment']['banking_billet']['customer']['phone_number'],
                "executor_ordem"                => 'Efi',
                "descricao_transacao"           => $descricao,
                "callback"                      => env('APP_URL') . '/callback/',
                "split_email"                   => null,
                "split_percentage"              => null,
                "banking_billet"                => json_encode($request['payment']['banking_billet']),
                "expire_at"                     => $responseData['expire_at'],
                "days_availability"             => $days_availability
            ];

            Log::debug('[+][EFI][REQUESTBILLET][SOLICITACAO DATA]: ' . json_encode($cashin));
            Solicitacoes::create($cashin);

            $ip = $request->header('X-Forwarded-For') ?
                $request->header('X-Forwarded-For') : ($request->header('CF-Connecting-IP') ?
                    $request->header('CF-Connecting-IP') :
                    $request->ip());
            $user = $request->user;
            if (!is_null($user->integracao_utmfy)) {
                $mensagem = "Boleto Gerado - " . env('APP_NAME');
                UtmfyTrait::gerarUTM('boleto', 'paid', $cashin, $user->integracao_utmfy, $ip, $mensagem);
            }

            return [
                "data" => [
                    "idTransaction" => $external_id,
                    "qrcode" => $responseData['qrcode'],
                    "qr_code_image_url" => 'https://quickchart.io/qr?text=' . $responseData['qrcode'],
                    "barcode" => $response->json()['data']['barcode'],
                    "download" => $responseData['download']
                ],
                "status" => 200
            ];
        } else {
            $resdata = $response->json();
            return [
                "status" => 422,
                "message" => $resdata['error_description'] ?? "Houve um erro. Verifique os dados e tente novamente!"
            ];
        }
    }

    public static function requestCardEfi($request)
    {
        $access_token = self::generateAccessToken();
        $request = $request['data'];

        $external_id = uniqid('CARD_');
        $request['metadata']['notification_url'] = url('efi/card/notification');
        $request['metadata']['custom_id'] = $external_id;

        $response = Http::withHeaders([
            'Authorization' => "Bearer $access_token",
            'Content-Type' => "application/json",
            'cert' => [self::$cert, ''],
            'verify' => false
        ])
            ->post(self::$baseUrl . "/v1/charge/one-step", $request);


        Log::debug('[+][EFI][REQUESTCARD][RESPONSE][BODY]: ' . $response->body());

        // dd($response->json());
        if ($response->successful()) {

            $responseData = $response->json();

            if (isset($responseData['data']['status']) && $responseData['data']['status'] == 'unpaid') {
                return [
                    "status" => 422,
                    "message" => $responseData['data']['refusal']['reason'] ?? "Dados do cartão inválido."
                ];
            } elseif (isset($responseData['data']['status']) && $responseData['data']['status'] == "approved") {
                $responseData = $responseData['data'];
                $efi = Efi::first();

                $amount = (float) $request['items'][0]['value'] / 100;
                $taxafixa = $efi->card_tx_fixed;

                $taxatotal = ((float) $amount * (float) $efi->card_tx_percent / 100);
                $deposito_liquido = (float) $amount - $taxatotal;
                $taxa_cash_in = $taxatotal;
                $descricao = "CARTAO";

                $deposito_liquido = $deposito_liquido - $taxafixa;
                $taxa_cash_in = $taxa_cash_in + $taxafixa;

                Log::debug("[+][EFI][REQUESTCARD][TAXA E VALORES]: [amount: $amount, liquido: $deposito_liquido, taxas: $taxa_cash_in]");
                $date = Carbon::now();

                $days_availability = $efi->card_days_availability;
                $cashin = [
                    "method"                        => "card",
                    "user_id"                       => $request['user']->username,
                    "externalreference"             => $responseData['charge_id'],
                    "amount"                        => $amount,
                    "client_name"                   => $request['payment']['credit_card']['customer']['name'],
                    "client_document"               => $request['payment']['credit_card']['customer']['cpf'],
                    "client_email"                  => $request['payment']['credit_card']['customer']['email'],
                    "date"                          => $date,
                    "status"                        => 'RELEASE',
                    "idTransaction"                 => $external_id,
                    "deposito_liquido"              => $amount,
                    "qrcode_pix"                    => "",
                    "paymentcode"                   => "",
                    "paymentCodeBase64"             => "",
                    "billet_download"               => "",
                    "adquirente_ref"                => 'Efi',
                    "taxa_cash_in"                  => $taxa_cash_in,
                    "taxa_pix_cash_in_adquirente"   => $taxatotal,
                    "taxa_pix_cash_in_valor_fixo"   => 0,
                    "client_telefone"               => $request['payment']['credit_card']['customer']['phone_number'],
                    "executor_ordem"                => 'Efi',
                    "descricao_transacao"           => $descricao,
                    "callback"                      => env('APP_URL') . '/callback/',
                    "split_email"                   => null,
                    "split_percentage"              => null,
                    "banking_billet"                => json_encode($request['payment']['credit_card']),
                    "expire_at"                     => $responseData['expire_at'] ?? null,
                    "days_availability"             => $days_availability
                ];

                Log::debug('[+][EFI][REQUESTCARD][SOLICITACAO DATA]: ' . json_encode($cashin));
                Solicitacoes::create($cashin);

                $ip = $request->header('X-Forwarded-For') ?
                    $request->header('X-Forwarded-For') : ($request->header('CF-Connecting-IP') ?
                        $request->header('CF-Connecting-IP') :
                        $request->ip());

                $user = $request['user'];
                if (!is_null($user->integracao_utmfy)) {
                    $msg = "Cartão de crédito - " . env('APP_NAME');
                    UtmfyTrait::gerarUTM('credit_card', 'paid', $cashin, $user->integracao_utmfy, $ip, $msg);
                }
                return [
                    "data" => [
                        "idTransaction" => $external_id,
                    ],
                    "status" => 200
                ];
            }
        } else {
            $resdata = $response->json();
            return [
                "status" => 422,
                "message" => "Erro ao processar a transação. Tente utilizar um outro cartão."
            ];
        }
    }

    public static function webhookCharge(string $notification)
    {

        $access_token = self::generateAccessToken();
        $endpoint = self::$baseUrl . "/v1/notification/" . $notification;
        Log::debug("ENDPOINT DE NOTIFICACAO: $endpoint");

        $setting = Efi::first();
        if (!$setting) {
            return false;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer " . $access_token,
            'Content-Type' => 'application/json'
        ])->get($endpoint);

        if ($response->successful()) {
            $responseData = $response->json();
            $atts = $responseData['data'];
            $last_att = $atts[count($atts) - 1];
            Log::debug("[+][EFI][WEBHOOK][LASTATT]:" . json_encode($last_att));

            $status = $last_att['status']['current'];
            $idTransaction = $last_att['custom_id'];

            if ($status == "paid") {
                $cashin = Solicitacoes::where('idTransaction', $idTransaction)->first();
                if (!$cashin || $cashin->status != "WAITING_FOR_APPROVAL") {
                    return response()->json(['status' => false]);
                }

                $updated_at = Carbon::now();
                $cashin->update(['status' => 'RELEASE', 'updated_at' => $updated_at]);

                $user = User::where('user_id', $cashin->user_id)->first();
                Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
                Helper::calculaSaldoLiquido($user->user_id);

                if (isset($user->gerente_id) && !is_null($user->gerente_id)) {
                    $gerente = User::where('id', $user->gerente_id)->first();
                    $gerente_porcentagem = $gerente->gerente_percentage;

                    $valor = (float) $cashin->taxa_cash_in * (float) $gerente_porcentagem / 100;

                    Transactions::create([
                        'user_id' => $user->user_id,
                        'gerente_id' => $user->gerente_id,
                        'solicitacao_id' => $cashin->id,
                        'comission_value' => $valor,
                        'transaction_percent' => $cashin->taxa_cash_in,
                        'comission_percent' => $gerente_porcentagem,
                    ]);


                    Helper::calculaSaldoLiquido($gerente->user_id);
                }


                $order = CheckoutOrders::where('idTransaction', $idTransaction)->first();
                if ($order) {
                    $order->update(['status' => 'pago']);
                    if (!is_null($user->webhook_url) && in_array('pago', (array) $user->webhook_endpoint)) {
                        Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                            ->post($user->webhook_url, [
                                'nome' => $order->name,
                                'cpf' => preg_replace('/\D/', '', $order->cpf),
                                'telefone' => preg_replace('/\D/', '', $order->telefone),
                                'email' => $order->email,
                                'status' => 'pago'
                            ]);
                    }
                }


                if ($cashin->callback) {
                    $payload = [
                        "status"            => "paid",
                        "idTransaction"     => $cashin->idTransaction,
                        "typeTransaction"   => "PIX"
                    ];

                    Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'accept' => 'application/json'
                    ])->post($cashin->callback, $payload);

                    Log::debug("[+][EFI][WEBHOOK] Send Callback: Para $cashin->callback -> Enviando...");
                    if ($cashin->callback && $cashin->callback != 'web') {
                        $payload = [
                            "status"            => "paid",
                            "idTransaction"     => $cashin->idTransaction,
                            "typeTransaction"   => "PIX"
                        ];

                        Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->post($cashin->callback, $payload);

                        $ip = "127.0.0.1";
                        if (!is_null($user->integracao_utmfy)) {
                            $mensagem = "Boleto Pago - " . env('APP_NAME');
                            UtmfyTrait::gerarUTM('boleto', 'paid', $cashin, $user->integracao_utmfy, $ip, $mensagem);
                        }

                        $success = 'paid';
                        return response()->json(['status' => $success]);
                    } else {
                        $order = CheckoutOrders::where('idTransaction', $idTransaction)->first();
                        if ($order) {
                            $order->update(['status' => 'pago']);
                            if (!is_null($user->webhook_url) && in_array('pago', (array) $user->webhook_endpoint)) {
                                Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                                    ->post($user->webhook_url, [
                                        'nome' => $order->name,
                                        'cpf' => preg_replace('/\D/', '', $order->cpf),
                                        'telefone' => preg_replace('/\D/', '', $order->telefone),
                                        'email' => $order->email,
                                        'status' => 'pago'
                                    ]);
                            }
                        }
                    }
                }
            } elseif ($status == "unpaid") {
                $cashin = Solicitacoes::where('idTransaction', $idTransaction)->first();
                $updated_at = Carbon::now();
                $cashin->update(['status' => 'CANCELLED', 'updated_at' => $updated_at]);
                $order = CheckoutOrders::where('idTransaction', $idTransaction)->first();
                if ($order) {
                    $order->update(['status' => 'cancelado']);
                }
            }
        }
    }

    /**
     * Processa saque automático diretamente via API
     */
    protected static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        if (self::generateCredentials()) {
            $payload = [
                "valor" => number_format($request->amount, '2', '.', ','),
                "pagador" => [
                    "chave" => self::$chave_pix,
                    "infoPagador" => "Segue o pagamento da conta"
                ],
                "favorecido" => [
                    "chave" => $request->pixKey
                ]
            ];

            $internal_id = str_replace('-', '', Str::uuid()->toString());

            // Fazer a requisição
            $response = Http::withOptions([
                'cert' => [self::$cert, ''],
                'verify' => true
            ])
                ->withHeaders([
                    'authorization' => 'Bearer ' . self::$access_token,
                    'Content-Type' => 'application/json'
                ])
                ->put(self::$baseUrl . '/v3/gn/pix/' . $internal_id, $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Criar registro de saque automático
                $idTransaction = $responseData['idEnvio'] ?? Str::uuid()->toString();
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
                    "end_to_end"            => $responseData['e2eId'] ?? $idTransaction,
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
            } else {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao processar saque automático via API."
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
