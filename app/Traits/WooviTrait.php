<?php

namespace App\Traits;

use App\Models\SolicitacoesCashOut;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Services\WooviService;
use App\Helpers\Helper;
use App\Traits\IPManagementTrait;
use App\Traits\UtmfyTrait;
use App\Helpers\TaxaFlexivelHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

trait WooviTrait
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
     * Processar pagamento via Woovi (Cash In)
     */
    public static function requestPaymentWoovi($request)
    {
        \Log::info('🔍 WooviTrait::requestPaymentWoovi - INÍCIO', [
            'checkout_id' => $request->checkout_id,
            'metodo' => $request->metodo,
            'valor_total' => $request->valor_total,
            'all_data' => $request->all(),
            'has_checkout_id' => $request->has('checkout_id')
        ]);
        
        try {
            $woovi = \App\Models\Woovi::first();
            if (!$woovi || !$woovi->status) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Woovi não configurado ou inativo."
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
                    \Log::info('🔍 WooviTrait: Usuário obtido via checkout', [
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

            Log::info('=== WOOVITRAIT REQUEST PAYMENT INICIADO ===');
            Log::info('WooviTrait::requestPaymentWoovi - Dados da requisição:', [
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

            Log::info('WooviTrait::requestPaymentWoovi - Cálculo de taxas:', [
                'amount_original' => $valor,
                'taxa_cash_in' => $taxa_cash_in,
                'deposito_liquido' => $valor_liquido,
                'descricao' => $descricao_taxa
            ]);

            $date = Carbon::now();
            $descricao = "Depósito PIX via Woovi - R$ " . number_format($valor, 2, ',', '.');

            $wooviService = new WooviService();

            // Validar CPF/CNPJ antes de enviar para o Woovi
            $documentNumber = $request->debtor_document_number ?? $request->cpf ?? $user->cpf_cnpj ?? null;
            
            // Se não houver documento, gerar um CPF válido para teste
            if (!$documentNumber || $documentNumber === '00000000000') {
                $documentNumber = Helper::generateValidCpf();
                Log::info('WooviTrait: Gerando CPF válido para teste', ['cpf_gerado' => $documentNumber]);
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

            // Dados para criar cobrança
            $chargeData = [
                'correlationID' => 'dep_' . $user->id . '_' . time(),
                'value' => $valor * 100, // Woovi usa centavos
                'comment' => $descricao,
                'customer' => [
                    'name' => $request->debtor_name ?? $request->name ?? $user->name,
                    'taxID' => $documentNumber,
                    'email' => $request->email ?? $user->email,
                    'phone' => $request->phone ?? $request->telefone ?? $user->telefone ?? '00000000000'
                ]
            ];

            $response = $wooviService->createCharge($chargeData);

            if (isset($response['error']) && $response['error']) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => $response['message']
                    ]
                ];
            }

            // Criar registro de solicitação
            $solicitacao = Solicitacoes::create([
                'user_id' => $user->user_id,
                'externalreference' => $response['charge']['correlationID'] ?? uniqid(),
                'amount' => $valor,
                'deposito_liquido' => $valor_liquido,
                'taxa_cash_in' => $taxa_cash_in,
                'taxa_pix_cash_in_adquirente' => 0,
                'taxa_pix_cash_in_valor_fixo' => 0,
                'client_name' => $request->debtor_name ?? $request->name ?? $user->name,
                'client_document' => $documentNumber,
                'client_email' => $request->email ?? $user->email,
                'client_telefone' => $request->phone ?? $request->telefone ?? $user->telefone ?? '00000000000',
                'executor_ordem' => 'woovi',
                'status' => 'WAITING_FOR_APPROVAL',
                'descricao_transacao' => $descricao_taxa,
                'idTransaction' => $response['charge']['correlationID'] ?? uniqid(),
                'woovi_identifier' => $response['charge']['identifier'] ?? null,
                'qrcode_pix' => $response['charge']['qrCodeImage'] ?? null,
                'paymentcode' => $response['charge']['pixKey'] ?? null,
                'paymentCodeBase64' => $response['charge']['qrCodeImage'] ?? null,
                'method' => 'PIX',
                'adquirente_ref' => 'woovi',
                'callback' => $request->postback ?? $user->webhook_url ?? env('APP_URL') . '/callback/',
                'split_email' => $request->split_email ?? null,
                'split_percentage' => $request->split_percentage ?? null,
                'date' => $date,
                'created_at' => $date,
                'updated_at' => $date
            ]);

            Log::info('WooviTrait::requestPaymentWoovi - Registro de solicitação criado:', [
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
                UtmfyTrait::gerarUTM('pix', 'waiting_payment', $solicitacao->toArray(), $user->integracao_utmfy, $ip, $msg);
            }

            Log::info('=== WOOVITRAIT REQUEST PAYMENT FINALIZADO ===');

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Cobrança PIX criada com sucesso",
                    "idTransaction" => $response['charge']['correlationID'],
                    "qrcode" => $response['charge']['brCode'] ?? null, // Código PIX para copiar e colar
                    "qr_code_image_url" => $response['charge']['qrCodeImage'] ?? null, // URL da imagem do QR Code
                    "charge" => [
                        "id" => $response['charge']['correlationID'],
                        "value" => $valor,
                        "qrCode" => $response['charge']['qrCodeImage'] ?? null,
                        "brCode" => $response['charge']['brCode'] ?? null,
                        "pixKey" => $response['charge']['pixKey'] ?? null,
                        "expiresAt" => $response['charge']['expiresAt'] ?? null
                    ],
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no WooviTrait::requestPaymentWoovi: ' . $e->getMessage());
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
     * Processar saque via Woovi (Cash Out)
     */
    public static function requestSaqueWoovi($request)
    {
        try {
            $woovi = \App\Models\Woovi::first();
            if (!$woovi || !$woovi->status) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => "Woovi não configurado ou inativo."
                    ]
                ];
            }

            // Usar o usuário já autenticado pelo middleware
            $user = $request->user();
            if (!$user) {
                return [
                    "status" => 404,
                    "data" => [
                        "status" => "error",
                        "message" => "Usuário não encontrado."
                    ]
                ];
            }

            // Verificar se o IP está autorizado para saques
            $ipCheck = self::checkIPForWithdraw($user);
            if (!$ipCheck['success']) {
                return [
                    "status" => 403,
                    "data" => [
                        "status" => "error",
                        "message" => $ipCheck['message'],
                        "client_ip" => $ipCheck['client_ip']
                    ]
                ];
            }

            $valor = (float) $request->amount;
            $setting = \App\Models\App::first();
            $user = $request->user();

            Log::info('=== WOOVITRAIT REQUEST SAQUE INICIADO ===');
            Log::info('WooviTrait::requestSaqueWoovi - Dados da requisição:', [
                'user_id' => $user->id,
                'username' => $user->username,
                'amount' => $valor,
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
            $taxaCalculada = \App\Helpers\TaxaSaqueHelper::calcularTaxaSaque($valor, $setting, $user, $isInterfaceWeb, $taxaPorFora);
            $cashout_liquido = $taxaCalculada['saque_liquido'];
            $taxa_cash_out = $taxaCalculada['taxa_cash_out'];
            $descricao_taxa = $taxaCalculada['descricao'];
            $valor_total_descontar = $taxaCalculada['valor_total_descontar'] ?? $valor;

            Log::info('WooviTrait::requestSaqueWoovi - Cálculo de taxas:', [
                'amount_original' => $valor,
                'taxa_cash_out' => $taxa_cash_out,
                'cashout_liquido' => $cashout_liquido,
                'descricao' => $descricao_taxa,
                'user_saldo' => $user->saldo,
                'is_interface_web' => $isInterfaceWeb
            ]);

            $date = Carbon::now();
            $descricao = "Saque PIX via Woovi - R$ " . number_format($valor, 2, ',', '.');

            // Verificar saldo considerando taxa por fora
            $saldo_necessario = $valor_total_descontar; // Sempre usar valor total a descontar
            if ($user->saldo < $saldo_necessario) {
                // Calcular valor máximo que pode ser sacado
                $valorMaximo = \App\Helpers\TaxaSaqueHelper::calcularValorMaximoSaque($user->saldo, $setting, $user, $isInterfaceWeb);
                
                Log::warning('WooviTrait::requestSaqueWoovi - Saldo insuficiente:', [
                    'user_saldo' => $user->saldo,
                    'valor_solicitado' => $valor,
                    'valor_total_descontar' => $saldo_necessario,
                    'valor_maximo_saque' => $valorMaximo['valor_maximo'],
                    'taxa_total' => $valorMaximo['taxa_total']
                ]);
                
                return [
                    "status" => 401,
                    "data" => [
                        "status" => "error",
                        "message" => "Saldo insuficiente para realizar a operação. Considere o valor + a taxa de saque.",
                        "valor_solicitado" => $valor,
                        "taxa_total" => $taxa_cash_out,
                        "valor_total_necessario" => $saldo_necessario,
                        "saldo_disponivel" => $user->saldo,
                        "deficit" => $saldo_necessario - $user->saldo,
                        "valor_maximo_saque" => $valorMaximo['valor_maximo'],
                        "saldo_restante" => $valorMaximo['saldo_restante']
                    ]
                ];
            }

            // Verificar se é saque automático ou manual
            if ($request->has('saque_automatico') && $request->saque_automatico) {
                return self::processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
            } else {
                return self::generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user);
            }

        } catch (\Exception $e) {
            Log::error('Erro no WooviTrait::requestSaqueWoovi: ' . $e->getMessage());
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
     * Processar saque automático via Woovi
     */
    protected static function processarSaqueAutomatico($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        try {
            $wooviService = new WooviService();

            // Validar chave PIX primeiro (desabilitado temporariamente para teste)
            // $validation = $wooviService->validatePixKey($request->pixKey, $request->pixKeyType);
            // if (!$validation['valid']) {
            //     return [
            //         "status" => 400,
            //         "data" => [
            //             "status" => "error",
            //             "message" => "Chave PIX inválida: " . $validation['message']
            //         ]
            //     ];
            // }

            // Dados para criar saque
            $withdrawalData = [
                'value' => $request->amount * 100, // Woovi usa centavos
                'pixKey' => $request->pixKey,
                'pixKeyType' => $request->pixKeyType,
                'description' => $descricao,
                'correlationID' => uniqid('woovi_payment_')
            ];

            $response = $wooviService->createWithdrawal($withdrawalData);

            if (isset($response['error']) && $response['error']) {
                return [
                    "status" => 500,
                    "data" => [
                        "status" => "error",
                        "message" => $response['message']
                    ]
                ];
            }

            // Criar registro de saque completado
            $solicitacao = SolicitacoesCashOut::create([
                'user_id' => $user->user_id,
                'externalreference' => $response['transactionId'] ?? uniqid('woovi_saque_'),
                'amount' => $request->amount,
                'beneficiaryname' => $user->name ?? 'Usuário',
                'beneficiarydocument' => $user->cpf_cnpj ?? '00000000000',
                'pix' => $request->pixKey,
                'pixkey' => $request->pixKey,
                'date' => $date,
                'status' => 'COMPLETED',
                'type' => 'PIX',
                'idTransaction' => $response['transactionId'] ?? uniqid(),
                'taxa_cash_out' => $taxa_cash_out,
                'cash_out_liquido' => $cashout_liquido,
                'descricao_transacao' => 'AUTOMATICO',
                'callback' => $user->webhook_url ?? null,
                'created_at' => $date,
                'updated_at' => $date
            ]);

            // Atualizar saldo do usuário
            // Para taxa por fora, descontar valor + taxa do saldo
            $taxaPorFora = \App\Models\App::first()->taxa_por_fora_api ?? true;
            $valor_para_descontar = $taxaPorFora ? ($request->amount + $taxa_cash_out) : $request->amount;
            
            Log::info('=== WOOVITRAIT::processarSaqueAutomatico - DESCONTO DE SALDO ===', [
                'user_id' => $user->user_id,
                'saldo_antes' => $user->saldo,
                'valor_saque' => $request->amount,
                'taxa_cash_out' => $taxa_cash_out,
                'taxa_por_fora' => $taxaPorFora,
                'valor_para_descontar' => $valor_para_descontar,
                'valor_sacado_antes' => $user->valor_sacado
            ]);
            
            Helper::decrementAmount($user, $valor_para_descontar, 'saldo');
            $user->increment('valor_sacado', $request->amount);
            
            // Log específico para saque
            \App\Helpers\BalanceLogHelper::logSaqueOperation(
                'SAQUE_REQUEST',
                $user,
                $request->amount,
                [
                    'adquirente' => 'WOOVI',
                    'valor_bruto' => $request->amount,
                    'valor_descontado' => $valor_para_descontar,
                    'taxa_cash_out' => $taxa_cash_out,
                    'taxa_por_fora' => $taxaPorFora,
                    'external_id' => $response['transactionId'] ?? $solicitacao->id_transacao,
                    'operacao' => 'processarSaqueAutomatico'
                ]
            );
            
            Log::info('WooviTrait::processarSaqueAutomatico - Saldo atualizado:', [
                'user_id' => $user->user_id,
                'saldo_depois' => $user->fresh()->saldo,
                'valor_sacado' => $user->fresh()->valor_sacado
            ]);

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Saque processado automaticamente com sucesso",
                    "transaction" => [
                        "id" => $response['transactionId'] ?? $solicitacao->id_transacao,
                        "value" => $request->amount,
                        "status" => "COMPLETED",
                        "processedAt" => $date->toISOString()
                    ],
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no WooviTrait::processarSaqueAutomatico: ' . $e->getMessage());
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro ao processar saque automático via Woovi."
                ]
            ];
        }
    }

    /**
     * Gerar transação de saque manual
     */
    protected static function generateTransactionPaymentManual($request, $taxa_cash_out, $cashout_liquido, $date, $descricao, $user)
    {
        try {
            $solicitacao = SolicitacoesCashOut::create([
                'user_id' => $user->user_id,
                'externalreference' => uniqid('woovi_manual_'),
                'amount' => $request->amount,
                'beneficiaryname' => $user->name ?? 'Usuário',
                'beneficiarydocument' => $user->cpf_cnpj ?? '00000000000',
                'pix' => $request->pixKey,
                'pixkey' => $request->pixKey,
                'date' => $date,
                'status' => 'PENDING',
                'type' => 'PIX',
                'idTransaction' => uniqid('woovi_'),
                'taxa_cash_out' => $taxa_cash_out,
                'cash_out_liquido' => $cashout_liquido,
                'descricao_transacao' => 'MANUAL',
                'created_at' => $date,
                'updated_at' => $date
            ]);

            return [
                "status" => 200,
                "data" => [
                    "status" => "success",
                    "message" => "Solicitação de saque criada com sucesso. Aguarde aprovação.",
                    "transaction" => [
                        "id" => $solicitacao->id_transacao,
                        "value" => $request->amount,
                        "status" => "PENDING",
                        "createdAt" => $date->toISOString()
                    ],
                    "solicitacao_id" => $solicitacao->id
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erro no WooviTrait::generateTransactionPaymentManual: ' . $e->getMessage());
            return [
                "status" => 500,
                "data" => [
                    "status" => "error",
                    "message" => "Erro ao criar solicitação de saque."
                ]
            ];
        }
    }

    /**
     * Libera saque manual (aprovação pelo admin) para registros criados como MANUAL/PENDING
     */
    public static function liberarSaqueManual($id)
    {
        try {
            \Log::info('WooviTrait::liberarSaqueManual - Iniciando liberação manual', ['id' => $id]);

            $cashout = \App\Models\SolicitacoesCashOut::where('id', $id)->first();
            if (!$cashout) {
                \Log::warning('WooviTrait::liberarSaqueManual - Solicitação não encontrada', ['id' => $id]);
                return back()->with('error', 'Solicitação de saque não encontrada.');
            }

            // Atualiza status e marca como liberado pelo admin
            $update = [
                'status' => 'COMPLETED',
                'descricao_transacao' => 'LIBERADOADMIN'
            ];

            \App\Models\SolicitacoesCashOut::where('id', $id)->update($update);
            \Log::info('WooviTrait::liberarSaqueManual - Solicitação atualizada com sucesso', ['id' => $id, 'update' => $update]);
            return back()->with('success', "Saque atualizado para 'PAGO' com sucesso!");
        } catch (\Exception $e) {
            \Log::error('WooviTrait::liberarSaqueManual - Exceção', ['message' => $e->getMessage()]);
            return back()->with('error', 'Erro ao liberar saque manual.');
        }
    }
}
