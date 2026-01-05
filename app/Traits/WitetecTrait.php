<?php

namespace App\Traits;

use App\Services\WitetecService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\{App, User, Witetec, Solicitacoes, SolicitacoesCashOut};
use App\Helpers\Helper;
use App\DTO\WitetecDTO\Enums\PixKeyType;
use App\DTO\WitetecDTO\Enums\DepositMethod;
use App\DTO\WitetecDTO\WithdrawDTO;
use App\DTO\WitetecDTO\DepositDTO;
use App\DTO\WitetecDTO\CustomerDTO;
use App\DTO\WitetecDTO\ItemDTO;

trait WitetecTrait
{
    protected static string $apiKey;
    protected static string $baseUrl;
    protected static string $txBilletFixed;
    protected static string $txBilletPercent;
    protected static string $txCardFixed;
    protected static string $txCardPercent;

    protected static function generateCredentialWitetec()
    {

        $setting = Witetec::first();
        if (!$setting) {
            return false;
        }

        self::$apiKey = $setting->api_token;
        self::$baseUrl = $setting->url;
        self::$txBilletFixed = $setting->tx_billet_fixed;
        self::$txBilletPercent = $setting->tx_billet_percent;
        self::$txCardFixed = $setting->tx_card_fixed;
        self::$txCardPercent = $setting->tx_card_percent;

        return true;
    }

    public static function requestDepositWitetec($request)
    {
        Log::info('WitetecTrait::requestDepositWitetec - INÍCIO', [
            'checkout_id' => $request->checkout_id ?? null,
            'metodo' => $request->metodo ?? null,
            'amount' => $request->amount,
            'all_data' => $request->all(),
            'has_checkout_id' => $request->has('checkout_id')
        ]);
        
        try {
            $witetecConfig = \App\Models\Witetec::first();
            if (!$witetecConfig || !$witetecConfig->status) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Witetec não configurado ou inativo."
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
                    Log::info('WitetecTrait: Usuário obtido via checkout', [
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

            Log::info('=== WITETECTRAIT REQUEST DEPOSIT INICIADO ===');
            Log::info('WitetecTrait::requestDepositWitetec - Dados da requisição:', [
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

            Log::info('WitetecTrait::requestDepositWitetec - Cálculo de taxas:', [
                'amount_original' => $valor,
                'taxa_cash_in' => $taxa_cash_in,
                'deposito_liquido' => $valor_liquido,
                'descricao' => $descricao_taxa
            ]);

            $date = Carbon::now();
            $descricao = "Depósito PIX via Witetec - R$ " . number_format($valor, 2, ',', '.');

            if (!self::generateCredentialWitetec()) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao gerar credenciais Witetec."
                    ]
                ];
            }

            // Validar CPF/CNPJ antes de enviar para o Witetec
            $documentNumber = $request->debtor_document_number ?? $request->cpf ?? $user->cpf_cnpj ?? null;
            
            // Se não houver documento, gerar um CPF válido para teste
            if (!$documentNumber || $documentNumber === '00000000000') {
                $documentNumber = \App\Helpers\Helper::generateValidCpf();
                Log::info('WitetecTrait: Gerando CPF válido para teste', ['cpf_gerado' => $documentNumber]);
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

            $customer = new CustomerDTO(
                $request->debtor_name ?? $request->name ?? $user->name,
                $request->email ?? $user->email,
                $request->phone ?? $request->telefone ?? $user->telefone ?? '11999999999',
                "CPF",
                $documentNumber
            );

            $item = new ItemDTO(
                "Produto X",
                $valor * 100,
                1,
                false,
                uniqid("PROD_")
            );

            $deposit = new DepositDTO(
                $valor * 100,
                DepositMethod::PIX,
                $customer,
                [$item],
                null
            );

            $api = new \App\Services\WitetecService(self::$baseUrl, self::$apiKey);
            $response = $api->deposit($deposit);

            if (isset($response['message'])) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao criar depósito Witetec: " . $response['message']
                    ]
                ];
            }

            if (!$response['status']) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao criar depósito Witetec"
                    ]
                ];
            }

            $responseData = $response['data'];

            // Criar registro de solicitação
            $solicitacao = Solicitacoes::create([
                'user_id' => $user->user_id,
                'externalreference' => $responseData['id'],
                'amount' => $valor,
                'deposito_liquido' => $valor_liquido,
                'taxa_cash_in' => $taxa_cash_in,
                'taxa_pix_cash_in_adquirente' => 0,
                'taxa_pix_cash_in_valor_fixo' => 0,
                'client_name' => $request->debtor_name ?? $request->name ?? $user->name,
                'client_document' => $documentNumber,
                'client_email' => $request->email ?? $user->email,
                'client_telefone' => $request->phone ?? $request->telefone ?? $user->telefone ?? '11999999999',
                'executor_ordem' => 'witetec',
                'status' => 'WAITING_FOR_APPROVAL',
                'descricao_transacao' => $descricao_taxa,
                'idTransaction' => $responseData['id'],
                'qrcode_pix' => $responseData['pix']['copyPaste'] ?? null,
                'paymentcode' => $responseData['pix']['copyPaste'] ?? null,
                'paymentCodeBase64' => $responseData['pix']['copyPaste'] ?? null,
                'method' => 'PIX',
                'adquirente_ref' => 'witetec',
                'callback' => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/',
                'split_email' => $request->split_email ?? null,
                'split_percentage' => $request->split_percentage ?? null,
                'date' => $date,
                'created_at' => $date,
                'updated_at' => $date
            ]);

            Log::info('WitetecTrait::requestDepositWitetec - Registro de solicitação criado:', [
                'solicitacao_id' => $solicitacao->id,
                'externalreference' => $solicitacao->externalreference,
                'amount' => $solicitacao->amount,
                'deposito_liquido' => $solicitacao->deposito_liquido,
                'taxa_cash_in' => $solicitacao->taxa_cash_in
            ]);

            Log::info('=== WITETECTRAIT REQUEST DEPOSIT FINALIZADO ===');

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Cobrança PIX criada com sucesso",
                    "idTransaction" => $responseData['id'],
                    "qrcode" => $responseData['pix']['copyPaste'] ?? null,
                    "qr_code_image_url" => 'https://quickchart.io/qr?text=' . urlencode($responseData['pix']['copyPaste'] ?? ''),
                    "charge" => [
                        "id" => $responseData['id'],
                        "value" => $valor,
                        "qrCode" => 'https://quickchart.io/qr?text=' . urlencode($responseData['pix']['copyPaste'] ?? ''),
                        "brCode" => $responseData['pix']['copyPaste'] ?? null,
                        "pixKey" => null,
                        "expiresAt" => null
                    ],
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no WitetecTrait::requestDepositWitetec: ' . $e->getMessage());
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro interno do servidor."
                ]
            ];
        }
    }

    public static function requestPaymentWitetec($request)
    {
        $data = $request->all();

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

        if (self::generateCredentialWitetec()) {
            $pixKeyType = PixKeyType::CPF;
            switch (strtolower($request->pixKeyType)) {
                case 'email':
                    $pixKeyType = PixKeyType::EMAIL;
                    break;
                case 'telefone':
                    $pixKeyType = PixKeyType::PHONE;
                    break;
                case 'aleatoria':
                    $pixKeyType = PixKeyType::EVP;
                    break;
                default:
                    $pixKeyType = PixKeyType::CPF;
                    break;
            }

            $payload = new WithdrawDTO(
                $cashout_liquido * 100,
                $request->pixKey,
                $pixKeyType,
                "PIX"
            );

            $api = new WitetecService(self::$baseUrl, self::$apiKey);
            $response = $api->withdraw($payload);


            if ($response['status']) {
                Helper::incrementAmount($user, $request->amount, 'valor_saque_pendente');
                Helper::decrementAmount($user, $cashout_liquido, 'saldo');

                $name = $request->user()->name;
                $responseData = $response['data'];

                $pixKey = $request->pixKey;

                switch ($request->pixKeyType) {
                    case 'cpf':
                    case 'cnpj':
                    case 'phone':
                        $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                        break;
                }

                $ip = $request->header('X-Forwarded-For') ?
                    $request->header('X-Forwarded-For') : ($request->header('CF-Connecting-IP') ?
                        $request->header('CF-Connecting-IP') :
                        $request->ip());

                $internal_id = str_replace('-', '', (string) Str::uuid());
                $internal_id = strtoupper($internal_id);

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

    public static function liberarSaqueManualWitetec($id)
    {
        //dd($id);
        if (self::generateCredentialWitetec()) {
            $cashout = SolicitacoesCashOut::where('id', $id)->first();
                       
            $pixKeyType = PixKeyType::CPF;
            switch (strtolower($cashout->pixKey)) {
                case 'email':
                    $pixKeyType = PixKeyType::EMAIL;
                    break;
                case 'telefone':
                    $pixKeyType = PixKeyType::PHONE;
                    break;
                case 'aleatoria':
                    $pixKeyType = PixKeyType::EVP;
                    break;
                default:
                    $pixKeyType = PixKeyType::CPF;
                    break;
            }

            $sacar = (float) number_format($cashout->cash_out_liquido, 2) * 100;
            $payload = new WithdrawDTO(
                $sacar,
                $cashout->pix,
                $pixKeyType,
                "PIX"
            );
//dd($payload);
            $api = new WitetecService(self::$baseUrl, self::$apiKey);
            $response = $api->withdraw($payload);
            if(isset($response['message'])){
                return back()->with('error', $response['message']);
            }
            if ($response['status']) {
                $responseData = $response['data'];
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
    }

    /**
     * Processa saque automático diretamente via API
     */
    protected static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        if (self::generateCredentialWitetec()) {
            $pixKeyType = PixKeyType::CPF;
            switch (strtolower($request->pixKeyType)) {
                case 'email':
                    $pixKeyType = PixKeyType::EMAIL;
                    break;
                case 'telefone':
                case 'phone':
                    $pixKeyType = PixKeyType::PHONE;
                    break;
                case 'aleatoria':
                    $pixKeyType = PixKeyType::EVP;
                    break;
            }

            $pixKey = $request->pixKey;
            if ($pixKeyType === PixKeyType::CPF || $pixKeyType === PixKeyType::PHONE) {
                $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
            }

            $payload = new WithdrawDTO(
                $cashout_liquido * 100,
                $pixKey,
                $pixKeyType,
                "PIX"
            );

            $api = new WitetecService(self::$baseUrl, self::$apiKey);
            $response = $api->withdraw($payload);

            if ($response['status']) {
                $responseData = $response['data'];
                
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
            } else {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Erro ao processar saque automático via API Witetec."
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
