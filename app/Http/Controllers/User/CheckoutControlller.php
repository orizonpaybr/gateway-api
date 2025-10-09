<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Adquirente;
use App\Models\CheckoutBuild;
use App\Models\CheckoutDepoimento;
use App\Models\CheckoutOrders;
use App\Models\SolicitacoesCashOut;
use App\Models\Solicitacoes;
use App\Models\UsersKey;
use App\Models\User;
use App\Traits\ApiTrait;
use App\Helpers\Helper;
use App\Traits\{PagarMeTrait, EfiTrait, MercadoPagoTrait, CashtimeTrait, XgateTrait, WitetecTrait, PixupTrait, WooviTrait};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CheckoutControlller extends Controller
{
    public function index(Request $request)
    {
        $buscar = $request->input('buscar');

        $query = CheckoutBuild::where("user_id", auth()->id());

        if (!is_null($buscar)) {
            $query->where('produto_name', 'LIKE', "%$buscar%");
        }

        $checkouts = $query->get();

        return view("profile.checkout.index", compact("checkouts"));
    }

    public function indexEdit($id, Request $request)
    {
        $checkout = CheckoutBuild::where('id_unico', $id)->firstOrFail();
        return view("profile.checkout.edit", compact('checkout'));
    }

    public function v1($id, Request $request)
    {
        $checkout = CheckoutBuild::where("id_unico", $id)->first();
        $user = User::where('id', $checkout->user_id)->first();
        $keys = UsersKey::where('user_id', $user->user_id)->first();
        $token = $keys->token;
        $secret = $keys->secret;

        return view('profile.checkout.v1', compact('checkout', 'secret', 'token'));
    }

    public function v2(Request $request)
    {
        $id = $request->input("id");
        $produto = CheckoutBuild::where("referencia", $id)->first();
        $keys = UsersKey::where('user_id', $produto->user_id)->first();
        $token = $keys->token;
        $secret = $keys->secret;

        return view('profile.checkout.v2', compact('produto', 'secret', 'token'));
    }

    public function create(Request $request)
    {
        // Log temporário para debug
        \Log::info('Dados recebidos no create:', $request->all());

        $validated = $request->validate([
            "produto_name" => "required|string",
            "produto_valor" => "required|numeric|min:0.01",
            "produto_descricao" => "required|string",
            "produto_tipo" => "required|string",
            "produto_tipo_cob" => "required|string",
            "methods" => "required|array|min:1",
            "methods.*" => "in:pix,card,billet"
        ], [
            'produto_valor.required' => 'O preço do produto é obrigatório.',
            'produto_valor.numeric' => 'O preço do produto deve ser um número válido.',
            'produto_valor.min' => 'O preço do produto deve ser maior que R$ 0,00.',
            'produto_name.required' => 'O nome do produto é obrigatório.',
            'produto_descricao.required' => 'A descrição do produto é obrigatória.',
            'produto_tipo.required' => 'O tipo do produto é obrigatório.',
            'produto_tipo_cob.required' => 'O tipo de cobrança é obrigatório.',
            'methods.required' => 'Selecione pelo menos um método de pagamento.',
            'methods.min' => 'Selecione pelo menos um método de pagamento.',
            'methods.*.in' => 'Método de pagamento inválido.'
        ]);

        $data = $request->except(['_token', '_method', '/checkout']);

        $data['user_id'] = auth()->id();
        $data['id_unico'] = Str::uuid();
        $data['produto_valor'] = str_replace([","], '.', $data['produto_valor']);
        $data['methods'] = json_encode($request->methods);
        CheckoutBuild::create($data);
        return redirect()->back()->with('success', 'Checkout cadastrado com sucesso com sucesso!');
    }

    public function edit($id, Request $request)
    {
        // Validação dos campos obrigatórios
        $request->validate([
            "produto_name" => "required|string",
            "produto_valor" => "required|numeric|min:0.01",
            "produto_descricao" => "required|string",
            "produto_tipo" => "required|string",
            "produto_tipo_cob" => "required|string"
        ], [
            'produto_valor.required' => 'O preço do produto é obrigatório.',
            'produto_valor.numeric' => 'O preço do produto deve ser um número válido.',
            'produto_valor.min' => 'O preço do produto deve ser maior que R$ 0,00.',
            'produto_name.required' => 'O nome do produto é obrigatório.',
            'produto_descricao.required' => 'A descrição do produto é obrigatória.',
            'produto_tipo.required' => 'O tipo do produto é obrigatório.',
            'produto_tipo_cob.required' => 'O tipo de cobrança é obrigatório.'
        ]);

        // Criamos o registro sem as imagens
        $checkoutBuild = CheckoutBuild::where('id', $id)->first();
        $checkoutDir = public_path("/checkouts/{$checkoutBuild->id}/");
        if (!file_exists($checkoutDir)) {
            mkdir($checkoutDir, 0755, true);
        }
        $data = collect($request->all())
            ->reject(function ($value, $key) {
                return preg_match('/^checkout_depoimentos_/', $key)
                    || in_array($key, ['_token', '_method', 'checkout_depoimentos_id', 'checkout_depoimentos_nome', 'checkout_depoimentos_depoimento', 'checkout_depoimentos_image']);
            })
            ->toArray();

        $data['methods'] = json_encode($request->methods);
        $data['produto_valor'] = str_replace([","], '.', $data['produto_valor']);
        
        // Processar URL da página de obrigado
        if ($request->has('url_pagina_vendas_default') && !empty($request->url_pagina_vendas_default)) {
            // Se está usando página padrão, usar a URL padrão
            $data['url_pagina_vendas'] = $request->url_pagina_vendas_default;
        } elseif (empty($data['url_pagina_vendas'])) {
            // Se não tem URL personalizada, usar página padrão
            $data['url_pagina_vendas'] = url('/obrigado?order_id=ORDER_ID');
        }
        
        // Atualiza campos principais
        $checkoutBuild->update($data);

        // Atualiza imagens únicas como produto/banner/logo/etc
        $images_checkout = ['produto_image', 'checkout_header_logo', 'checkout_header_image', 'checkout_banner'];
        $dataImg = [];

        foreach ($images_checkout as $field) {
            if ($request->hasFile($field)) {
                $filename = 'checkout_' . $field . '.' . $request->file($field)->getClientOriginalExtension();
                $request->file($field)->move($checkoutDir, $filename);
                $dataImg[$field] = "/checkouts/{$checkoutBuild->id}/{$filename}";
            }
        }

        // Atualiza imagens únicas, se houver
        if (!empty($dataImg)) {
            $checkoutBuild->update($dataImg);
        }


        $checkoutBuild->fill([
            'checkout_timer_active' => $request->has('checkout_timer_active'),
            'checkout_header_logo_active' => $request->has('checkout_header_logo_active'),
            'checkout_header_image_active' => $request->has('checkout_header_image_active'),
            'checkout_topbar_active' => $request->has('checkout_topbar_active'),
            // outros campos...
        ])->save();

        return redirect()->back()->with('success', 'Checkout alterado com sucesso!');
    }

    public function destroy($id)
    {
        // Buscar o checkout pelo ID
        $checkout = CheckoutBuild::find($id);

        if (!$checkout) {
            return redirect()->back()->with('error', 'Checkout não encontrado.');
        }

        // Verifica se o usuário autenticado pode excluir esse checkout
        /* if (auth()->user()->user_id !== $checkout->user_id) {
            return redirect()->back()->with('error', 'Você não tem permissão para excluir este checkout.');
        } */

        // Deleta as imagens associadas, se existirem
        if ($checkout->logo_produto) {
            Storage::disk('public')->delete($checkout->logo_produto);
        }
        if ($checkout->banner_produto) {
            Storage::disk('public')->delete($checkout->banner_produto);
        }

        // Exclui o checkout do banco de dados
        $checkout->delete();

        return redirect()->back()->with('success', 'Checkout excluído com sucesso!');
    }

    public function gerarPedido(Request $request)
    {
        try {
            \Log::info('🚀 Iniciando gerarPedido:', [
                'metodo' => $request->metodo,
                'checkout_id' => $request->checkout_id,
                'dados_recebidos' => $request->except(['_token'])
            ]);

            $data = $request->except(['_token']);
            
            \Log::info('📝 Criando CheckoutOrders com dados:', $data);
            $venda = CheckoutOrders::create($data);
            \Log::info('✅ CheckoutOrders criado com ID:', ['id' => $venda->id]);
            
            // Buscar o usuário do checkout para usar sua adquirente preferida
            $checkout = CheckoutBuild::where('id', $request->checkout_id)->first();
            if (!$checkout) {
                \Log::error('❌ Checkout não encontrado:', ['checkout_id' => $request->checkout_id]);
                return response()->json(['status' => 'error', 'message' => 'Checkout não encontrado.']);
            }
            
            $user = User::where('id', $checkout->user_id)->first();
            if (!$user) {
                \Log::error('❌ Usuário não encontrado:', ['user_id' => $checkout->user_id]);
                return response()->json(['status' => 'error', 'message' => 'Usuário não encontrado.']);
            }
            
            // Determinar o tipo de pagamento para buscar a adquirente correta
            $paymentType = in_array($request->metodo, ['card', 'billet']) ? 'card_billet' : 'pix';
            $default = Helper::adquirenteDefault($user->user_id, $paymentType);

            \Log::info('🏦 Adquirente detectada no gerarPedido:', [
                'default' => $default,
                'metodo' => $request->metodo,
                'payment_type' => $paymentType
            ]);

            // Verificar se é cartão e se deve usar PrimePay7
            if ($request->metodo == 'card' && $default == 'primepay7') {
                \Log::info('✅ Redirecionando para processamento PrimePay7');
                return $this->processCardPaymentPrimePay7($request, $data, $venda);
            }

            // Verificar se é cartão PrimePay7 (compatibilidade com código antigo)
            if ($request->metodo == 'card_primepay7') {
                return $this->processCardPaymentPrimePay7($request, $data, $venda);
            }
        
        if ($request->metodo == 'card') {
            \Log::info('ℹ️ Usando EFI para processar cartão');
            $creditcard = json_decode($data['credit_card']);
            $installment = json_decode($data['installment']);
            $checkout = CheckoutBuild::where('id', $request->checkout_id)->first();
            $user = User::where('id', $checkout->user_id)->first();
            $payload = [
                "user" => $user,
                "data" => [
                    "items" => [
                        [
                            'name'      => $checkout->produto_name,
                            'value'     => (int) $data['valor_total'] * 100,
                            'amount'    => 1
                        ]
                    ],
                    "payment" => [
                        "credit_card" => [
                            "customer" => [
                                "name"          => $data['name'],
                                "cpf"           => str_replace(['(', ')', ' ', '.', '-', '/'], '', $data['cpf']),
                                "email"         => $data['email'],
                                "phone_number"  => str_replace(['(', ')', ' ', '.', '-', '/'], '', $data['telefone']),
                            ],
                            "installments" => $installment->installment,
                            "payment_token" => $data['payment_token']
                        ]
                    ]
                ]
            ];

            if (!is_null($user->webhook_url) && in_array('gerado', (array) $user->webhook_endpoint)) {
                Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                    ->post($user->webhook_url, [
                        'nome' => $venda->name,
                        'cpf' => preg_replace('/\D/', '', $venda->cpf),
                        'telefone' => preg_replace('/\D/', '', $venda->telefone),
                        'email' => $venda->email,
                        'status' => 'pendente'
                    ]);
            }
            $newrequest = new Request($payload);
            //            dd($newrequest->all());
            $response = EfiTrait::requestCardEfi($payload);
            //dd($response);
            $status = isset($response['status']) && $response['status'] == 200 ? 'success' : 'error';
            if ($status == "success") {
                $cahsout = Solicitacoes::where('idTransaction', $response['data']['idTransaction'])->first();
                $cahsout->update(['descricao_transacao' => 'PRODUTO']);

                $venda->idTransaction = $response['data']['idTransaction'];
                $venda->qrcode = "";
                $venda->save();
                $valor_text = "R$ " . number_format($venda->valor_total, '2', ',', '.');
                return response()->json(["status" => $status, "data" => $response['data'], "valor_text" => $valor_text]);
            } else {
                return response()->json(['status' => 'error', 'message' => $response['message'] ?? "Verifique e tente novamente."]);
            }
        } elseif ($request->metodo == 'billet') {

            $data = $request->all();
            $checkout = CheckoutBuild::where('id', $request->checkout_id)->first();
            $user = User::where('id', $checkout->user_id)->first();
            $payload = [
                "user" => $user,
                "items" => [
                    [
                        'name'      => $checkout->produto_name,
                        'value'     => (int) $data['valor_total'] * 100,
                        'amount'    => 1
                    ]
                ],
                "payment" => [
                    "banking_billet" => [
                        "customer"          => [
                            "name"          => $data['name'],
                            "cpf"           => str_replace(['(', ')', ' ', '.', '-', '/'], '', $data['cpf']),
                            "email"         => $data['email'],
                            "phone_number"  => str_replace(['(', ')', ' ', '.', '-', '/'], '', $data['telefone']),

                        ],
                        "expire_at" => "2023-12-15",
                        "configurations" => [
                            "fine" => 200,
                            "interest" => 33
                        ]
                    ]
                ]

            ];

            if (isset($data['cep'])) {
                $address = [
                    "street"        => $data['endereco'],
                    "number"        => $data['numero'] ?? 0,
                    "neighborhood"  => $data['bairro'],
                    "zipcode"       => str_replace(['.', '-', ' '], '', $data['cep']),
                    "city"          => $data['cidade'],
                    "complement"    => $data['complemento'] ?? "",
                    "state"         => $data['estado']
                ];

                $payload['payment']['banking_billet']['customer']["address"] = $address;
            }

            //dd($payload);

            if (!is_null($user->webhook_url) && in_array('gerado', (array) $user->webhook_endpoint)) {
                Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                    ->post($user->webhook_url, [
                        'nome' => $venda->name,
                        'cpf' => preg_replace('/\D/', '', $venda->cpf),
                        'telefone' => preg_replace('/\D/', '', $venda->telefone),
                        'email' => $venda->email,
                        'status' => 'pendente'
                    ]);
            }
            $newrequest = new Request($payload);
            //            dd($newrequest->all());
            $response = EfiTrait::requestBoletoEfi($newrequest);
            //dd($response);
            $status = isset($response['status']) && $response['status'] == 200 ? 'success' : 'error';
            if ($status == "success") {
                $cahsout = Solicitacoes::where('idTransaction', $response['data']['idTransaction'])->first();
                $cahsout->update(['descricao_transacao' => 'PRODUTO']);

                $venda->idTransaction = $response['data']['idTransaction'];
                $venda->qrcode = $response['data']['qrcode'];
                $venda->save();
                $valor_text = "R$ " . number_format($venda->valor_total, '2', ',', '.');
                return response()->json(["status" => $status, "data" => $response['data'], "valor_text" => $valor_text]);
            } else {
                return response()->json(['status' => 'error', 'message' => $response['message'] ?? "Verifique e tente novamente."]);
            }
        }

        if (!$venda) {
            return response()->json(['status' => 'error', 'message' => 'Houve um erro. Tente novamente!']);
        }

        $checkout = CheckoutBuild::where('id', $venda->checkout_id)->first();
        $user = User::where('id', $checkout->user_id)->first();
        $chaves = UsersKey::where('user_id', $user->user_id)->first();

        $dataRequest = [
            'token' => $chaves->token,
            'secret' => $chaves->secret,
            'amount' => $venda->valor_total,
            'debtor_name' => $venda->name,
            'email' => $venda->email,
            'debtor_document_number' => $venda->cpf,
            'phone' => $venda->telefone,
            'method_pay' => 'pix',
            'postback' => 'web',
            'user' => $user
        ];

        if (!is_null($user->webhook_url) && in_array('gerado', (array) $user->webhook_endpoint)) {
            Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->post($user->webhook_url, [
                    'nome' => $venda->name,
                    'cpf' => preg_replace('/\D/', '', $venda->cpf),
                    'telefone' => preg_replace('/\D/', '', $venda->telefone),
                    'email' => $venda->email,
                    'status' => 'pendente'
                ]);
        }

        $request = new Request($dataRequest);


        switch ($default) {
            case 'cashtime':
                $response = CashtimeTrait::requestDepositCashtime($request);
                break;
            case 'mercadopago':
                $response = MercadoPagoTrait::requestDepositMercadoPago($request);
                break;
            case 'efi':
                $response = EfiTrait::requestDepositEfi($request);
                break;
            case 'pagarme':
                $response = PagarMeTrait::requestDepositPagarme($request);
                break;
            case 'xgate':
                $response = XgateTrait::requestDepositXgate($request);
                break;
            case 'witetec':
                $response = WitetecTrait::requestDepositWitetec($request);
                break;
            case 'pixup':
                $response = PixupTrait::requestDepositPixup($request);
                break;
            case 'woovi':
                // Para Woovi, usar dados originais do checkout + dados de API
                $wooviData = array_merge($data, $dataRequest);
                $wooviRequest = new Request($wooviData);
                $response = WooviTrait::requestPaymentWoovi($wooviRequest);
                break;
        }

        $status = isset($response['status']) && $response['status'] == 200 ? 'success' : 'error';
        if ($status == "success") {
            $cahsout = Solicitacoes::where('idTransaction', $response['data']['idTransaction'])->first();
            $cahsout->update(['descricao_transacao' => 'PRODUTO']);

            $venda->idTransaction = $response['data']['idTransaction'];
            $venda->qrcode = $response['data']['qrcode'];
            $venda->save();
            $valor_text = "R$ " . number_format($venda->valor_total, '2', ',', '.');
            return response()->json(["status" => $status, "data" => $response['data'], "valor_text" => $valor_text]);
        } else {
            \Log::error('❌ Erro no processamento PIX:', [
                'response' => $response,
                'status' => $status
            ]);
            return response()->json(['status' => 'error', 'message' => "Verifique e tente novamente."]);
        }
        
        } catch (\Exception $e) {
            \Log::error('❌ Erro geral no gerarPedido:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'dados' => $request->except(['_token'])
            ]);
            return response()->json(['status' => 'error', 'message' => 'Erro interno. Tente novamente.']);
        }
    }

    public function statusPedido(Request $request)
    {
        $data = $request->except(['/checkout/cliente/pedido/status']);
        $order = CheckoutOrders::where('idTransaction', $data['idTransaction'])->first();

        $status = $order->status;
        $message = "Aguardando pagamento...";
        if ($status == 'pago') {
            $message = "Pagamento realizado com sucesso!";
        }
        return response()->json(compact('status', 'message'));
        //dd($data, $order);
    }

    public function salvarDepoimento(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'depoimento' => 'required|string|max:1000',
            'image' => 'nullable|image|max:2048',
            'avatar' => 'nullable|string',
            'id' => 'nullable|string',
            'checkout_id' => 'required'
        ]);

        $depoimento = [
            'id' => $validated['id'],
            'nome' => $validated['nome'],
            'depoimento' => $validated['depoimento'],
            'avatar' => $validated['avatar'] ?? null,
            'checkout_id' => $validated['checkout_id'],
        ];

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = 'dep_' . $depoimento['id'] . '.' . $file->getClientOriginalExtension();
            $path = "checkouts/{$depoimento['id']}/";
            $file->move(public_path($path), $filename);
            $depoimento['avatar'] = '/' . $path . $filename;
        }
        //dd($depoimento);
        // Validação e sanitização dos dados antes de inserir
        $depoimento = array_map('strip_tags', $depoimento);
        $depoimento = array_map('trim', $depoimento);
        
        // Aqui você pode salvar em banco se quiser
        if (is_null($depoimento['id'])) {
            unset($depoimento['id']);
            $depoimento = DB::table('checkout_depoimentos')->insert($depoimento);
        } else {
            // Validação adicional para update
            $existingDepoimento = DB::table('checkout_depoimentos')->where('id', $depoimento['id'])->first();
            if ($existingDepoimento) {
                DB::table('checkout_depoimentos')->where('id', $depoimento['id'])->update($depoimento);
            }
        }


        return response()->json([
            'success' => true,
            'depoimento' => $depoimento
        ]);
    }


    public function removerDepoimento(Request $request)
    {
        $id = $request->input('id');

        if (!$id) {
            return response()->json(['success' => false, 'message' => 'ID não informado.'], 400);
        }

        $depoimento = CheckoutDepoimento::find($id);

        if (!$depoimento) {
            return response()->json(['success' => false, 'message' => 'Depoimento não encontrado.'], 404);
        }

        try {
            $depoimento->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao remover depoimento.']);
        }
    }

    /**
     * Processa pagamento com cartão usando PrimePay7
     */
    private function processCardPaymentPrimePay7(Request $request, array $data, CheckoutOrders $venda)
    {
        Log::info('=== PROCESSANDO PAGAMENTO PRIMEPAY7 CARD ===');
        Log::info('Dados recebidos:', $data);

        try {
            $checkout = CheckoutBuild::where('id', $request->checkout_id)->first();
            $user = User::where('id', $checkout->user_id)->first();

            if (!$checkout || !$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Dados do checkout ou usuário não encontrados'
                ]);
            }

            // Inicializar serviço PrimePay7
            $primePay7Service = new \App\Services\PrimePay7Service();

            // Preparar dados da venda conforme documentação PrimePay7
            $saleData = [
                'amount' => (int) ($data['valor_total'] * 100), // Converter para centavos
                'installments' => (int) ($data['installments'] ?? 1),
                
                // Items conforme documentação: title, unitPrice, quantity, tangible
                'items' => [
                    [
                        'title' => $checkout->produto_name,
                        'unitPrice' => (int) ($data['valor_total'] * 100),
                        'quantity' => 1,
                        'tangible' => $checkout->produto_tipo === 'fisico' // true se produto físico
                    ]
                ],
                
                // Customer conforme documentação: document deve ser um objeto
                'customer' => [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'document' => [
                        'type' => 'cpf',
                        'number' => str_replace(['(', ')', ' ', '.', '-', '/'], '', $data['cpf'])
                    ],
                    'phone' => str_replace(['(', ')', ' ', '.', '-', '/'], '', $data['telefone'])
                ],
                
                'card' => [
                    'hash' => $data['card']['hash'] ?? null,
                    'number' => $data['card']['number'] ?? null,
                    'holderName' => $data['card']['holderName'] ?? null,
                    'expirationMonth' => $data['card']['expirationMonth'] ?? null,
                    'expirationYear' => $data['card']['expirationYear'] ?? null,
                    'cvv' => $data['card']['cvv'] ?? null,
                ]
            ];

            // Adicionar dados 3DS se fornecidos
            if (isset($data['threeDS'])) {
                $saleData['threeDS'] = $data['threeDS'];
            }

            // Adicionar returnURL para 3DS REDIRECT
            $saleData['returnURL'] = url('/checkout/callback-primepay7');
            
            // Adicionar postbackUrl para receber atualizações de status
            $saleData['postbackUrl'] = url('/checkout/webhook/primepay7');

            Log::info('PrimePay7 Card Sale Data:', $saleData);

            // Chamar API PrimePay7
            $response = $primePay7Service->createCardSale($saleData);

            // Verificar se houve erro (o serviço retorna 'error' e 'status_code' em caso de erro)
            if (isset($response['error']) || (isset($response['status_code']) && $response['status_code'] >= 400)) {
                // Erro na API PrimePay7
                $errorMessage = 'Erro ao processar pagamento';
                
                // Tentar extrair mensagem de erro mais específica
                if (isset($response['error']['message'])) {
                    $errorMessage = $response['error']['message'];
                } elseif (isset($response['error']['error'])) {
                    // Erros de validação da API
                    $validationErrors = [];
                    foreach ($response['error']['error'] as $field => $errors) {
                        if (is_array($errors)) {
                            foreach ($errors as $error) {
                                if (is_string($error)) {
                                    $validationErrors[] = $error;
                                } elseif (is_array($error)) {
                                    $validationErrors = array_merge($validationErrors, array_values($error));
                                }
                            }
                        } elseif (is_string($errors)) {
                            $validationErrors[] = $errors;
                        }
                    }
                    if (!empty($validationErrors)) {
                        $errorMessage = implode('; ', $validationErrors);
                    }
                }
                
                Log::error('❌ Erro na criação da venda PrimePay7:', [
                    'error' => $response['error'] ?? 'Erro desconhecido',
                    'status_code' => $response['status_code'] ?? 'unknown',
                    'error_message' => $errorMessage
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => $errorMessage
                ]);
            }

            // Sucesso - a API PrimePay7 retorna diretamente os dados da transação
            if (isset($response['id'])) {
                // Atualizar pedido com dados da transação
                $venda->update([
                    'idTransaction' => $response['id'],
                    'qrcode' => '',
                    'status' => 'pendente' // Status em português conforme ENUM da tabela
                ]);

                Log::info('✅ Pagamento PrimePay7 criado com sucesso:', [
                    'transaction_id' => $response['id'],
                    'status' => $response['status'] ?? 'unknown',
                    'amount' => $response['amount'] ?? 0
                ]);

                // Preparar resposta com dados para 3DS se necessário
                $responseData = [
                    'id' => $response['id'],
                    'order_id' => $venda->id,
                    'status' => $response['status'] ?? 'pending',
                    'valor_text' => "R$ " . number_format($venda->valor_total, 2, ',', '.')
                ];

                // Adicionar dados 3DS se presentes
                if (isset($response['threeDS'])) {
                    $responseData['threeDS'] = $response['threeDS'];
                }

                // Disparar webhook se configurado
                if (!is_null($user->webhook_url) && in_array('gerado', (array) $user->webhook_endpoint)) {
                    Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                        ->post($user->webhook_url, [
                            'nome' => $venda->name,
                            'cpf' => preg_replace('/\D/', '', $venda->cpf),
                            'telefone' => preg_replace('/\D/', '', $venda->telefone),
                            'email' => $venda->email,
                            'status' => 'pendente',
                            'transaction_id' => $response['id']
                        ]);
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $responseData
                ]);
            }

            // Se chegou aqui, resposta inesperada
            Log::error('❌ Resposta inesperada da PrimePay7:', $response);
            return response()->json([
                'status' => 'error',
                'message' => 'Resposta inesperada da API de pagamento'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Exceção no processamento PrimePay7 Card:', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno ao processar pagamento: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Verifica o status de uma transação (polling)
     */
    public function checkTransactionStatus(Request $request)
    {
        try {
            $orderId = $request->input('order_id');
            
            if (!$orderId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order ID não fornecido'
                ], 400);
            }

            $order = CheckoutOrders::find($orderId);

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Pedido não encontrado'
                ], 404);
            }

            Log::info('🔍 Verificando status da transação:', [
                'order_id' => $orderId,
                'transaction_id' => $order->idTransaction,
                'current_status' => $order->status
            ]);

            // Mapear status interno (português) para frontend
            $statusMap = [
                'gerado' => 'processing',
                'pendente' => 'processing',
                'pago' => 'approved',
                'cancelado' => 'cancelled',
                'encaminhado' => 'approved',
                'entregue' => 'approved'
            ];

            $frontendStatus = $statusMap[$order->status] ?? 'processing';

            return response()->json([
                'status' => 'success',
                'order_status' => $frontendStatus,
                'transaction_id' => $order->idTransaction,
                'valor_total' => $order->valor_total
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao verificar status da transação:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao verificar status'
            ], 500);
        }
    }

    /**
     * Webhook/Postback da PrimePay7 para receber atualizações de transações
     */
    public function primepay7Webhook(Request $request)
    {
        try {
            $data = $request->all();
            
            Log::info('📬 PrimePay7 Webhook recebido:', $data);

            // Validar estrutura do webhook
            if (!isset($data['type']) || !isset($data['data'])) {
                Log::warning('⚠️ Webhook PrimePay7 com estrutura inválida');
                return response()->json(['status' => 'error', 'message' => 'Invalid webhook structure'], 400);
            }

            $transactionData = $data['data'];
            $transactionId = $transactionData['id'] ?? null;
            $status = $transactionData['status'] ?? null;

            if (!$transactionId || !$status) {
                Log::warning('⚠️ Webhook PrimePay7 sem ID ou status');
                return response()->json(['status' => 'error', 'message' => 'Missing transaction ID or status'], 400);
            }

            // Buscar o pedido pelo ID da transação
            $order = CheckoutOrders::where('idTransaction', $transactionId)->first();

            if (!$order) {
                Log::warning('⚠️ Pedido não encontrado para transação PrimePay7', ['transaction_id' => $transactionId]);
                return response()->json(['status' => 'error', 'message' => 'Order not found'], 404);
            }

            Log::info('📦 Pedido encontrado:', [
                'order_id' => $order->id,
                'current_status' => $order->status,
                'new_status' => $status
            ]);

            // Mapear status da PrimePay7 para status interno (português)
            $statusMap = [
                'waiting_payment' => 'pendente',
                'pending' => 'pendente',
                'processing' => 'pendente',
                'approved' => 'pago',
                'paid' => 'pago',
                'refused' => 'cancelado',
                'cancelled' => 'cancelado',
                'refunded' => 'cancelado',
                'chargeback' => 'cancelado',
                'in_protest' => 'pendente'
            ];

            $newStatus = $statusMap[$status] ?? 'pendente';

            // Buscar checkout e usuário
            $checkout = CheckoutBuild::where('id', $order->checkout_id)->first();
            if (!$checkout) {
                Log::warning('⚠️ Checkout não encontrado para o pedido', ['order_id' => $order->id]);
                return response()->json(['status' => 'error', 'message' => 'Checkout not found'], 404);
            }

            $user = User::where('id', $checkout->user_id)->first();
            if (!$user) {
                Log::warning('⚠️ Usuário não encontrado para o checkout', ['checkout_id' => $checkout->id]);
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }

            // Se o status for "pago" e ainda não foi processado como pago
            if ($newStatus === 'pago' && $order->status !== 'pago') {
                Log::info('💰 Processando pagamento aprovado...');

                // Calcular taxa de depósito usando o helper centralizado
                $setting = \App\Models\App::first();
                $taxaCalculada = \App\Helpers\TaxaFlexivelHelper::calcularTaxaDeposito($order->valor_total, $setting, $user);
                $deposito_liquido = $taxaCalculada['deposito_liquido'];
                $taxa_cash_in = $taxaCalculada['taxa_cash_in'];

                // Criar registro na tabela de solicitações (transações financeiras)
                $solicitacao = \App\Models\Solicitacoes::create([
                    'user_id' => $user->user_id,
                    'externalreference' => 'PRIMEPAY7_CARD_' . $transactionId,
                    'amount' => $order->valor_total,
                    'client_name' => $order->name,
                    'client_document' => preg_replace('/\D/', '', $order->cpf),
                    'client_email' => $order->email,
                    'date' => now(),
                    'status' => 'PAID_OUT',
                    'idTransaction' => $transactionId,
                    'deposito_liquido' => $deposito_liquido,
                    'qrcode_pix' => '',
                    'paymentcode' => '',
                    'paymentCodeBase64' => '',
                    'adquirente_ref' => 'PrimePay7',
                    'taxa_cash_in' => $taxa_cash_in,
                    'taxa_pix_cash_in_adquirente' => 0,
                    'taxa_pix_cash_in_valor_fixo' => 0,
                    'client_telefone' => $order->telefone,
                    'executor_ordem' => 'PrimePay7 Card',
                    'descricao_transacao' => 'Pagamento com Cartão - ' . ($checkout->produto_name ?? 'Produto'),
                ]);

                // Creditar o saldo do usuário
                \App\Helpers\Helper::incrementAmount($user, $deposito_liquido, 'saldo');
                \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);

                Log::info('✅ Saldo creditado ao usuário:', [
                    'user_id' => $user->user_id,
                    'valor_bruto' => $order->valor_total,
                    'valor_liquido' => $deposito_liquido,
                    'taxa' => $taxa_cash_in
                ]);

                // Se o usuário tiver gerente, criar comissão
                if (isset($user->gerente_id) && !is_null($user->gerente_id)) {
                    $gerente = User::where('id', $user->gerente_id)->first();
                    if ($gerente) {
                        $gerente_porcentagem = $gerente->gerente_percentage ?? 0;
                        $comissao_valor = (float)$taxa_cash_in * (float)$gerente_porcentagem / 100;

                        \App\Models\Transactions::create([
                            'user_id' => $user->user_id,
                            'gerente_id' => $user->gerente_id,
                            'solicitacao_id' => $solicitacao->id,
                            'comission_value' => $comissao_valor,
                            'transaction_percent' => $taxa_cash_in,
                            'comission_percent' => $gerente_porcentagem,
                        ]);

                        \App\Helpers\Helper::calculaSaldoLiquido($gerente->user_id);

                        Log::info('💼 Comissão do gerente registrada:', [
                            'gerente_id' => $gerente->id,
                            'comissao' => $comissao_valor,
                            'percentual' => $gerente_porcentagem
                        ]);
                    }
                }
            }

            // Atualizar status do pedido
            $order->update([
                'status' => $newStatus
            ]);

            Log::info('✅ Status do pedido atualizado:', [
                'order_id' => $order->id,
                'old_status' => $order->status,
                'new_status' => $newStatus,
                'transaction_id' => $transactionId
            ]);

            if ($checkout) {
                
                // Disparar webhook do usuário se configurado
                if ($user && !is_null($user->webhook_url) && in_array($newStatus, ['pago', 'cancelado'])) {
                    $webhookPayload = [
                        'nome' => $order->name,
                        'cpf' => preg_replace('/\D/', '', $order->cpf),
                        'telefone' => preg_replace('/\D/', '', $order->telefone),
                        'email' => $order->email,
                        'status' => $newStatus,
                        'transaction_id' => $transactionId,
                        'valor_total' => $order->valor_total,
                        'metodo_pagamento' => $transactionData['paymentMethod'] ?? 'credit_card'
                    ];

                    Log::info('📤 Disparando webhook do usuário:', [
                        'webhook_url' => $user->webhook_url,
                        'status' => $newStatus
                    ]);

                    Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])->post($user->webhook_url, $webhookPayload);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao processar webhook PrimePay7:', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }
}
