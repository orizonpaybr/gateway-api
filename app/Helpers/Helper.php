<?php

namespace App\Helpers;

use App\Models\Adquirente;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Models\App;
use App\Models\CheckoutBuild;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Helper
{

    /**
     * Retorna a adquirente padrão baseado no tipo de pagamento
     * 
     * @param int|null $user_id ID do usuário
     * @param string $paymentType Tipo de pagamento: 'pix' ou 'card_billet'
     * @return string|null Referência da adquirente
     */
    public static function adquirenteDefault($user_id = null, $paymentType = 'pix')
    {
        Log::info('Helper::adquirenteDefault - User ID recebido', [
            'user_id' => $user_id,
            'payment_type' => $paymentType
        ]);
        
        // Se um user_id foi fornecido, verificar se o usuário tem preferência
        if ($user_id) {
            $user = User::where('username', $user_id)->first();
            Log::info('Helper::adquirenteDefault - Usuário encontrado', ['found' => $user ? 'Sim' : 'Não']);
            
            if ($user) {
                // Verificar adquirente específica baseada no tipo de pagamento
                if ($paymentType === 'card_billet') {
                    // Cartão e Boleto
                    Log::info('Helper::adquirenteDefault - Verificando adquirente Cartão+Boleto', [
                        'preferred' => $user->preferred_adquirente_card_billet ?? 'NULL',
                        'override' => $user->adquirente_card_billet_override ? 'Sim' : 'Não'
                    ]);
                    
                    if ($user->preferred_adquirente_card_billet && $user->adquirente_card_billet_override) {
                        $adquirentePreferida = Adquirente::where('referencia', $user->preferred_adquirente_card_billet)
                            ->where('status', 1)
                            ->first();
                        if ($adquirentePreferida) {
                            Log::info('Helper::adquirenteDefault - Retornando adquirente Cartão+Boleto preferida', [
                                'referencia' => $adquirentePreferida->referencia
                            ]);
                            return $adquirentePreferida->referencia;
                        }
                    }
                } else {
                    // PIX (padrão)
                    Log::info('Helper::adquirenteDefault - Verificando adquirente PIX', [
                        'preferred' => $user->preferred_adquirente ?? 'NULL',
                        'override' => $user->adquirente_override ? 'Sim' : 'Não'
                    ]);
                    
                    if ($user->preferred_adquirente && $user->adquirente_override) {
                        $adquirentePreferida = Adquirente::where('referencia', $user->preferred_adquirente)
                            ->where('status', 1)
                            ->first();
                        if ($adquirentePreferida) {
                            Log::info('Helper::adquirenteDefault - Retornando adquirente PIX preferida', [
                                'referencia' => $adquirentePreferida->referencia
                            ]);
                            return $adquirentePreferida->referencia;
                        }
                    }
                }
            }
        }
        
        // Fallback para adquirente padrão global do sistema baseada no tipo
        if ($paymentType === 'card_billet') {
            $adquirente = Adquirente::where('is_default_card_billet', 1)->first();
            Log::info('Helper::adquirenteDefault - Adquirente Cartão+Boleto padrão global', [
                'referencia' => $adquirente ? $adquirente->referencia : 'NULL'
            ]);
        } else {
            $adquirente = Adquirente::where('is_default', 1)->first();
            Log::info('Helper::adquirenteDefault - Adquirente PIX padrão global', [
                'referencia' => $adquirente ? $adquirente->referencia : 'NULL'
            ]);
        }
        
        return $adquirente ? $adquirente->referencia : null;
    }

    // NOVO
     public static function calculaSaldoLiquido($user_id)
    {
        try {
            Log::info('=== HELPER::calculaSaldoLiquido INICIADO ===', ['user_id' => $user_id]);
            
            // Busca o usuário uma única vez para evitar múltiplas consultas
            $user = User::where('user_id', $user_id)->first();

            if (!$user) {
                Log::error('calculaSaldoLiquido: Usuário não encontrado', ['user_id' => $user_id]);
                return false;
            }

            Log::info('calculaSaldoLiquido: Usuário encontrado', [
                'user_id' => $user_id,
                'saldo_atual' => $user->saldo,
                'valor_saque_pendente_atual' => $user->valor_saque_pendente
            ]);

            // Soma dos depósitos líquidos com status "PAID_OUT"
            $totalDepositoLiquido = Solicitacoes::where('user_id', $user_id)
                ->where('status', 'PAID_OUT')
                ->sum('deposito_liquido');

            Log::info('calculaSaldoLiquido: Depósitos líquidos', [
                'user_id' => $user_id,
                'total_deposito_liquido' => $totalDepositoLiquido
            ]);

            // CORREÇÃO 1: Considerar TODOS os status de saque bem-sucedido
            // CORREÇÃO 2: Incluir as taxas no cálculo do total de saques
            $totalSaquesPagos = SolicitacoesCashOut::where('user_id', $user_id)
                ->whereIn('status', ['PAID_OUT', 'COMPLETED']) // Agora inclui PAID_OUT
                ->sum(DB::raw('amount + taxa_cash_out')); // Soma valor + taxa

            Log::info('calculaSaldoLiquido: Saques pagos', [
                'user_id' => $user_id,
                'total_saques_pagos' => $totalSaquesPagos,
                'query_status' => ['PAID_OUT', 'COMPLETED']
            ]);

            // CORREÇÃO 2: Considerar TODOS os saques pendentes, pois representam saldo bloqueado
            // Removemos a verificação de descrição que estava causando o bug.
            $totalSaquesPendentes = SolicitacoesCashOut::where('user_id', $user_id)
                ->where('status', 'PENDING')
                ->sum('amount');

            Log::info('calculaSaldoLiquido: Saques pendentes', [
                'user_id' => $user_id,
                'total_saques_pendentes' => $totalSaquesPendentes,
                'query_status' => 'PENDING'
            ]);

            // Cálculo do saldo líquido
            $saldoLiquido = (float) $totalDepositoLiquido - (float) $totalSaquesPagos;

            Log::info('calculaSaldoLiquido: Cálculo base', [
                'user_id' => $user_id,
                'total_deposito_liquido' => $totalDepositoLiquido,
                'total_saques_pagos' => $totalSaquesPagos,
                'saldo_liquido_base' => $saldoLiquido
            ]);

            // Lógica de comissões (mantida como estava)
            if ($user->gerente_id) {
                $totalcomissoes = \App\Models\Transactions::where('gerente_id', $user->id)->sum('comission_value');
                $saldoLiquido += (float) $totalcomissoes;
                
                Log::info('calculaSaldoLiquido: Comissões aplicadas', [
                    'user_id' => $user_id,
                    'gerente_id' => $user->gerente_id,
                    'total_comissoes' => $totalcomissoes,
                    'saldo_liquido_pos_comissoes' => $saldoLiquido
                ]);
            }
            
            // Atualiza o saldo e o valor de saque pendente do usuário
            $saldoAnterior = $user->saldo;
            $valorSaquePendenteAnterior = $user->valor_saque_pendente;
            
            // CORREÇÃO: NUNCA recalcular saldo automaticamente
            // O saldo deve ser gerenciado apenas pelas operações manuais (saques/depósitos)
            Log::info('calculaSaldoLiquido: Mantendo saldo atual - não recalculando automaticamente', [
                'user_id' => $user_id,
                'saldo_atual' => $user->saldo,
                'saldo_calculado' => $saldoLiquido - $totalSaquesPendentes,
                'diferenca' => $user->saldo - ($saldoLiquido - $totalSaquesPendentes)
            ]);
            
            // Apenas atualiza o valor_saque_pendente, mantém o saldo atual
            $user->valor_saque_pendente = $totalSaquesPendentes;
            
            Log::info('calculaSaldoLiquido: Valores finais calculados', [
                'user_id' => $user_id,
                'saldo_anterior' => $saldoAnterior,
                'saldo_novo' => $user->saldo,
                'diferenca_saldo' => $user->saldo - $saldoAnterior,
                'valor_saque_pendente_anterior' => $valorSaquePendenteAnterior,
                'valor_saque_pendente_novo' => $user->valor_saque_pendente,
                'diferenca_saque_pendente' => $user->valor_saque_pendente - $valorSaquePendenteAnterior,
                'total_saques_pendentes' => $totalSaquesPendentes
            ]);
            
            $resultado = $user->save();
            
            Log::info('calculaSaldoLiquido: Resultado do save', [
                'user_id' => $user_id,
                'save_sucesso' => $resultado,
                'saldo_final' => $user->fresh()->saldo
            ]);
            
            Log::info('=== HELPER::calculaSaldoLiquido FINALIZADO ===', ['user_id' => $user_id]);
            
            return $resultado; // Salva as alterações e retorna true/false

        } catch (\Exception $e) {
            Log::error('Erro em calculaSaldoLiquido', [
                'user_id' => $user_id, 
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // ANTIGO
    // public static function calculaSaldoLiquido($user_id)
    // {
    //     try {
    //         // Soma dos depósitos líquidos com status "PAID_OUT"
    //         $totalDepositoLiquido = Solicitacoes::where('user_id', $user_id)
    //             ->where('status', 'PAID_OUT')
    //             ->sum('deposito_liquido');

    //         // Soma dos saques aprovados com status "COMPLETED"
    //         $totalSaquesAprovados = SolicitacoesCashOut::where('user_id', $user_id)
    //             ->where('status', 'COMPLETED')
    //             ->sum('amount');

    //         $totalSaldoBloqueado = SolicitacoesCashOut::where('user_id', $user_id)
    //             ->where('status', 'PENDING')
    //             ->where('descricao_transacao', 'WEB')
    //             ->sum('amount');


    //         // Cálculo do saldo líquido
    //         $saldoLiquido = (float) $totalDepositoLiquido - (float) $totalSaquesAprovados - (float)$totalSaldoBloqueado;
    //         // Busca o usuário atual para verificar se ele tem um gerente
    //         $currentUser = User::where('user_id', $user_id)->first();

    //         // Se o usuário existir e tiver um gerente_id, busca o gerente
    //         if ($currentUser && $currentUser->gerente_id) {
    //             $gerente = User::find($currentUser->gerente_id);
    //             if ($gerente) {
    //                 // Soma as comissões onde o usuário atual é o gerente
    //                 $totalcomissoes = Transactions::where('gerente_id', $currentUser->id)->sum('comission_value');
    //                 $saldoLiquido += (float) $totalcomissoes;
    //             }
    //         }
    //         // Atualizar o saldo do usuário
    //         // Encontrar o usuário pelo user_id
    //         $user = User::where('user_id', $user_id)->first();

    //         // Se o usuário for encontrado, atualizar o saldo
    //         if ($user) {
    //             $user->saldo = $saldoLiquido;
    //             $user->valor_saque_pendente = $totalSaldoBloqueado;
    //             return $user->save(); // Salva as alterações e retorna true/false
    //         }

    //         return false; // Retorna false se o usuário não for encontrado
    //     } catch (\Exception $e) {
    //         return false;
    //     }
    // }

    public static function calcularSaldoLiquidoUsuarios()
    {
        $users = User::get();
        foreach ($users as $user) {
            self::calculaSaldoLiquido($user->user_id);
        }
    }

    public static function generateValidCpf($pontuado = false)
    {
        $n1 = rand(0, 9);
        $n2 = rand(0, 9);
        $n3 = rand(0, 9);
        $n4 = rand(0, 9);
        $n5 = rand(0, 9);
        $n6 = rand(0, 9);
        $n7 = rand(0, 9);
        $n8 = rand(0, 9);
        $n9 = rand(0, 9);

        // Calcula o primeiro dígito verificador
        $d1 = $n9 * 2 + $n8 * 3 + $n7 * 4 + $n6 * 5 + $n5 * 6 + $n4 * 7 + $n3 * 8 + $n2 * 9 + $n1 * 10;
        $d1 = 11 - ($d1 % 11);
        $d1 = ($d1 >= 10) ? 0 : $d1;

        // Calcula o segundo dígito verificador
        $d2 = $d1 * 2 + $n9 * 3 + $n8 * 4 + $n7 * 5 + $n6 * 6 + $n5 * 7 + $n4 * 8 + $n3 * 9 + $n2 * 10 + $n1 * 11;
        $d2 = 11 - ($d2 % 11);
        $d2 = ($d2 >= 10) ? 0 : $d2;

        if ($pontuado) {
            return sprintf(
                '%d%d%d.%d%d%d.%d%d%d-%d%d',
                $n1,
                $n2,
                $n3,
                $n4,
                $n5,
                $n6,
                $n7,
                $n8,
                $n9,
                $d1,
                $d2
            );
        } else {
            return sprintf(
                '%d%d%d%d%d%d%d%d%d%d%d',
                $n1,
                $n2,
                $n3,
                $n4,
                $n5,
                $n6,
                $n7,
                $n8,
                $n9,
                $d1,
                $d2
            );
        }
    }

    public static function getSetting()
    {
        $settings = App::first();
        if (!$settings) {
            // Retorna um objeto App vazio ou lança uma exceção
            // para evitar erros de propriedade nula em todo o sistema.
            throw new \Exception("Configurações do aplicativo não encontradas.");
        }
        return $settings;
    }

    /**
     * @deprecated Use GamificationService::getNiveis() instead
     */
    public static function getNiveis()
    {
        return app(\App\Services\GamificationService::class)->getNiveis();
    }

    /**
     * @deprecated Use GamificationService::meuNivel() instead
     */
    public static function meuNivel($user)
    {
        return app(\App\Services\GamificationService::class)->meuNivel($user);
    }


    public static function incrementAmount(User $user, $valor, $campo)
{
    // ======================================================================
    // LOG DE DEPURAÇÃO CRÍTICO - REMOVER DEPOIS DE ENCONTRAR O BUG
    // ======================================================================
    Log::warning("==== FUNÇÃO INCREMENTAMOUNT ACIONADA ====", [
        'user_id' => $user->user_id,
        'campo_afetado' => $campo,
        'valor_incrementado' => $valor,
        'saldo_anterior' => $user->$campo,
        'trilha_de_execucao' => json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10)) // A "CAIXA PRETA"
    ]);
    // ======================================================================

    // (Aqui continua o resto do seu código original da função)
    $usuario = $user->toArray();
    $novovalor = $usuario[$campo] + (float)$valor;
    $user->update([$campo => $novovalor]);
    $user->save();
}

    public static function decrementAmount(User $user, $valor, $campo)
    {
        Log::info('=== HELPER::decrementAmount INICIADO ===', [
            'user_id' => $user->user_id,
            'valor' => $valor,
            'campo' => $campo,
            'valor_antes' => $user->$campo
        ]);

        $usuario = $user->toArray();
        $novovalor = $usuario[$campo] - (float)$valor;
        
        Log::info('Helper::decrementAmount - Cálculo', [
            'user_id' => $user->user_id,
            'valor_antes' => $usuario[$campo],
            'valor_decremento' => (float)$valor,
            'novo_valor' => $novovalor
        ]);
        
        $user->update([$campo => $novovalor]);
        $user->save();
        
        Log::info('Helper::decrementAmount - Atualização concluída', [
            'user_id' => $user->user_id,
            'valor_final' => $user->fresh()->$campo,
            'diferenca' => $usuario[$campo] - $user->fresh()->$campo
        ]);
        
        Log::info('=== HELPER::decrementAmount FINALIZADO ===', ['user_id' => $user->user_id]);
    }

    public static function getPendingAprove()
    {
        return $totalSaldoBloqueado = SolicitacoesCashOut::where('status', 'PENDING')
            ->where('descricao_transacao', 'WEB')
            ->count();
    }

    public static function getProdutosPaid($userId)
    {
        $checkout = CheckoutBuild::where('user_id', $userId)->get();
        $orders = 0;
        foreach ($checkout as $check) {
            $orders += $check->orders->where('status', 'pago')->count();
        }
        return $orders;
    }

    public static function salvarArquivo($request, $inputName, $pasta = 'uploads')
    {
        if ($request->hasFile($inputName)) {
            $file = $request->file($inputName);
            
            // Validação rigorosa de segurança
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];
            $extension = strtolower($file->getClientOriginalExtension());
            
            if (!in_array($extension, $allowedExtensions)) {
                throw new \InvalidArgumentException('Tipo de arquivo não permitido: ' . $extension);
            }
            
            // Verificar MIME type para prevenir arquivos maliciosos
            $allowedMimes = [
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf', 'application/msword', 
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            
            $mimeType = $file->getMimeType();
            if (!in_array($mimeType, $allowedMimes)) {
                throw new \InvalidArgumentException('Tipo MIME não permitido: ' . $mimeType);
            }
            
            // Renomear arquivo com extensão segura
            $filename = uniqid() . '.' . $extension;

            // Salvar o arquivo em storage/app/public/$pasta com nome personalizado
            $path = $file->storeAs("public/$pasta", $filename);

            if ($path) {
                return "/storage/$pasta/" . $filename;
            }
        }

        return null;
    }


    public static function gerarPessoa()
    {
        $url = "https://www.4devs.com.br/ferramentas_online.php";

        $data = [
            'acao' => 'gerar_pessoa',
            'sexo' => 'I',
            'pontuacao' => 'N',
            'idade' => 0,
            'cep_estado' => '',
            'txt_qtde' => 1,
            'cep_cidade' => ''
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Referer' => 'https://www.4devs.com.br/gerador_de_pessoas',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0',
        ])->asForm()->post($url, $data);

        if ($response->successful()) {
            $dados = $response->json();
            if (isset($dados[0]['nome']) && isset($dados[0]['cpf']) && isset($dados[0]['email'])) {
                return $dados[0];
            }
        }

        return null;
    }

    public static function soNumero($str)
    {
        return preg_replace("/[^0-9]/", "", $str);
    }

    public static function MakeToken($array)
    {
        if (is_array($array)) {
            $output =  '{"status": true';
            $interacao = 0;
            foreach ($array as $key => $value) {
                $output .=  ',"' . $key . '"' . ': "' . $value . '"';
            }
            $output .= "}";
        } else {
            $er_txt = self::Decode('QVakfW0DwcOie2aD9kog9oRx81VtX73oY1Vn91o7YVamZVa2eVaxYkwofGadZGadfGope2aB9zJgbVapYXJgX5R6YWJgeGgg9h');
            $output = str_replace('_', '&nbsp;', $er_txt);
            exit($output);
        }
        return self::Encode($output);
    }

    public static function Encode($texto)
    {
        $retorno = "";
        $saidaSubs = "";
        $texto = base64_encode($texto);
        $busca0 = array("a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v", "x", "w", "y", "z", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "=");
        $subti0 = array("8", "e", "9", "f", "b", "d", "h", "g", "j", "i", "m", "o", "k", "z", "l", "w", "4", "s", "r", "u", "t", "x", "v", "p", "6", "n", "7", "2", "1", "5", "q", "3", "y", "0", "c", "a", "");

        for ($i = 0; $i < strlen($texto); $i++) {
            $ti = array_search($texto[$i], $busca0);
            if ($busca0[$ti] == $texto[$i]) {
                $saidaSubs .= $subti0[$ti];
            } else {
                $saidaSubs .= $texto[$i];
            }
        }
        $retorno = $saidaSubs;

        return $retorno;
    }

    public static function Decode($texto)
    {
        $retorno = "";
        $saidaSubs = "";
        $busca0 = array("8", "e", "9", "f", "b", "d", "h", "g", "j", "i", "m", "o", "k", "z", "l", "w", "4", "s", "r", "u", "t", "x", "v", "p", "6", "n", "7", "2", "1", "5", "q", "3", "y", "0", "c", "a");
        $subti0 = array("a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v", "x", "w", "y", "z", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9");

        for ($i = 0; $i < strlen($texto); $i++) {
            $ti = array_search($texto[$i], $busca0);
            if ($busca0[$ti] == $texto[$i]) {
                $saidaSubs .= $subti0[$ti];
            } else {
                $saidaSubs .= $texto[$i];
            }
        }

        $retorno = base64_decode($saidaSubs);

        return $retorno;
    }

    public static function detectarTipoPix(string $chave): string
    {
        $chave = trim($chave);
        $somenteNumeros = preg_replace('/\D/', '', $chave);

        function validarCPF($cpf): bool
        {
            $cpf = preg_replace('/[^0-9]/', '', $cpf);

            if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
                return false;
            }

            for ($t = 9; $t < 11; $t++) {
                $d = 0;
                for ($c = 0; $c < $t; $c++) {
                    $d += $cpf[$c] * (($t + 1) - $c);
                }

                $d = ((10 * $d) % 11) % 10;

                if ($cpf[$c] != $d) {
                    return false;
                }
            }

            return true;
        }

        function validarCNPJ($cnpj): bool
        {
            $cnpj = preg_replace('/[^0-9]/', '', $cnpj);

            if (strlen($cnpj) != 14 || preg_match('/(\d)\1{13}/', $cnpj)) {
                return false;
            }

            $t = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $d1 = 0;

            for ($i = 0; $i < 12; $i++) {
                $d1 += $cnpj[$i] * $t[$i];
            }

            $d1 = $d1 % 11;
            $d1 = ($d1 < 2) ? 0 : 11 - $d1;

            if ($cnpj[12] != $d1) {
                return false;
            }

            array_unshift($t, 6);
            $d2 = 0;

            for ($i = 0; $i < 13; $i++) {
                $d2 += $cnpj[$i] * $t[$i];
            }

            $d2 = $d2 % 11;
            $d2 = ($d2 < 2) ? 0 : 11 - $d2;

            return $cnpj[13] == $d2;
        }

        // Verifica se é um CPF válido
        if (strlen($somenteNumeros) === 11 && validarCPF($somenteNumeros)) {
            return 'cpf';
        }

        // Verifica se é um CNPJ válido
        if (strlen($somenteNumeros) === 14 && validarCNPJ($somenteNumeros)) {
            return 'cnpj';
        }

        // Verifica se é um email
        if (filter_var($chave, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        // Verifica se é um telefone
        if (
            preg_match('/^(\+?55)?\d{10,11}$/', $somenteNumeros) &&
            !validarCPF($somenteNumeros) // Evita que um CPF válido seja confundido com telefone
        ) {
            return 'phone';
        }

        // Caso não encaixe em nenhum dos tipos conhecidos
        return 'random';
    }

    public static function validarCPF($cpf) {
        // Remover caracteres não numéricos
        $cpf = preg_replace('/\D/', '', $cpf);
    
        // Verificar se o CPF tem 11 dígitos
        if (strlen($cpf) != 11) {
            return false;
        }
    
        // Verificar se todos os números são iguais (exemplo: 111.111.111.11)
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
    
        // Validar o primeiro dígito verificador
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += $cpf[$i] * (10 - $i);
        }
        $resto = $soma % 11;
        $digito1 = $resto < 2 ? 0 : 11 - $resto;
        if ($cpf[9] != $digito1) {
            return false;
        }
    
        // Validar o segundo dígito verificador
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += $cpf[$i] * (11 - $i);
        }
        $resto = $soma % 11;
        $digito2 = $resto < 2 ? 0 : 11 - $resto;
        if ($cpf[10] != $digito2) {
            return false;
        }
    
        return true;
    }

    public static function validarCNPJ($cnpj) {
        // Remover caracteres não numéricos
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        
        // Verificar se o CNPJ tem 14 dígitos
        if (strlen($cnpj) != 14) {
            return false;
        }
        
        // Verificar se todos os números são iguais
        if (preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }
        
        // Validar primeiro dígito verificador
        $t = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $d1 = 0;
        
        for ($i = 0; $i < 12; $i++) {
            $d1 += $cnpj[$i] * $t[$i];
        }
        
        $d1 = $d1 % 11;
        $d1 = ($d1 < 2) ? 0 : 11 - $d1;
        
        if ($cnpj[12] != $d1) {
            return false;
        }
        
        // Validar segundo dígito verificador
        array_unshift($t, 6);
        $d2 = 0;
        
        for ($i = 0; $i < 13; $i++) {
            $d2 += $cnpj[$i] * $t[$i];
        }
        
        $d2 = $d2 % 11;
        $d2 = ($d2 < 2) ? 0 : 11 - $d2;
        
        return $cnpj[13] == $d2;
    }

    public static function verifyPixType($pixkey)
    {
        // Verificar se é CPF (11 dígitos numéricos)
        if (preg_match('/^\d{11}$/', $pixkey)) {
            // Verificar se é um CPF válido
            if (self::validarCPF($pixkey)) {
                return 'cpf';
            }
            
            // Caso contrário, pode ser um telefone
            return 'phone';
        }

        // Verificar se é CNPJ (14 dígitos numéricos)
        if (preg_match('/^\d{14}$/', $pixkey)) {
            return 'cnpj';
        }

        // Verificar se é email (formato padrão de email)
        if (filter_var($pixkey, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        // Verificar se é chave aleatória (random) - normalmente começa com '000' ou um formato específico
        if (preg_match('/^[a-zA-Z0-9]{36}$/', $pixkey)) {
            return 'random';
        }
    
        return 'invalid';
    }
}
