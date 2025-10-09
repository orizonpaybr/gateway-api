<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Enums\PixKeyType;
use App\Traits\CashtimeTrait;
use App\Traits\EfiTrait;
use App\Traits\XgateTrait;
use App\Traits\WitetecTrait;
use App\Traits\MercadoPagoTrait;
use App\Traits\PixupTrait;
use App\Traits\BSPayTrait;
use App\Traits\WooviTrait;
use App\Traits\AsaasTrait;
use App\Traits\PrimePay7Trait;
use App\Traits\XDPagTrait;
use App\Traits\IPManagementTrait;
use App\Models\User;
use App\Models\App;
use App\Helpers\Helper;
use App\Models\Adquirente;
use App\Models\SolicitacoesCashOut;
use App\Helpers\ApiResponseStandardizer;

class SaqueController extends Controller
{
    public function makePayment(Request $request)
    {
        // Verificar se o usuário está autenticado
        $user = $request->user();
        Log::info('SaqueController - Verificação de usuário', [
            'user_from_request' => $user ? 'Presente' : 'Ausente',
            'user_id' => $user ? $user->id : 'N/A',
            'request_data' => $request->all()
        ]);
        
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Usuário não autenticado'], 401);
        }
        
        Helper::calculaSaldoLiquido($user->user_id);
        $setting = App::first();
        if (!$setting) {
            return response()->json(['status' => 'error', 'message' => 'Configurações do aplicativo não encontradas.'], 500);
        }

        $default = Helper::adquirenteDefault($user->user_id);
        if (!$default) {
            return response()->json(['status' => 'error', 'message' => 'Nenhum adquirente configurado.'], 500);
        }

        $user = User::where('id', $user->id)->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Usuário não encontrado.'], 404);
        }

        // Determinar se é saque via interface web ou API
        $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';
        
        // Debug: Log da requisição
        Log::info('[IP_CHECK] Debug da requisição', [
            'user_id' => $user->user_id,
            'baasPostbackUrl' => $request->input('baasPostbackUrl'),
            'is_interface_web' => $isInterfaceWeb
        ]);
        
        // Nota: A verificação de IP é feita pelo middleware CheckAllowedIP

        // Verificar saldo disponível (considerando valores em mediação)
        $saldoDisponivel = (float) $user->saldo;
        
        // Calcular valores bloqueados em mediação
        $valoresEmMediacao = \App\Models\Solicitacoes::where('user_id', $user->id)
            ->where('status', 'MEDIATION')
            ->sum('deposito_liquido');
        
        $saldoRealDisponivel = $saldoDisponivel - (float) $valoresEmMediacao;
        
        if ($saldoRealDisponivel < (float)$request->amount) {
            $mensagem = 'Saldo Insuficiente.';
            if ($valoresEmMediacao > 0) {
                $mensagem .= ' Você possui R$ ' . number_format($valoresEmMediacao, 2, ',', '.') . ' em mediação que não podem ser sacados.';
            }
            return response()->json(['status' => 'error', 'message' => $mensagem], 401);
        }

        try {
            $validated = $request->validate([
                'token' =>    ['required', 'string'],
                'secret' =>    ['required', 'string'],
                'amount' =>    ['required'],
                'pixKey' => ['required', 'string'],
                'pixKeyType' =>    ['required', 'string', 'in:cpf,cnpj,email,telefone,phone,aleatoria,random,crypto'],
                'baasPostbackUrl' =>    ['required', 'string']
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422); // Status code 422 para erros de validação
        }

        // Verificar valor mínimo de saque - priorizar configuração específica do usuário
        $valorMinimoSaque = $user->valor_minimo_saque ?? $setting->saque_minimo;
        
        if ($request->amount < $valorMinimoSaque) {
            $saqueminimo = "R$ " . number_format($valorMinimoSaque, '2', ',', '.');
            return response()->json([
                'status' => 'error',
                'message' => "O saque mínimo é de $saqueminimo.",
            ], 401);
        }

        // Verificar se o saque automático está ativo
        if ($setting->saque_automatico) {
            // Verificar se o valor está dentro do limite para saque automático
            if ($request->amount <= $setting->limite_saque_automatico) {
                // Processar saque automático
                return $this->processarSaqueAutomatico($request, $default, $setting, $isInterfaceWeb);
            } else {
                // Valor acima do limite, processar como manual
                return $this->processarSaqueManual($request, $default, $isInterfaceWeb);
            }
        } else {
            // Modo manual ativo, processar como manual
            return $this->processarSaqueManual($request, $default, $isInterfaceWeb);
        }
    }

    /**
     * Processa saque automático - executa o pagamento diretamente
     */
    private function processarSaqueAutomatico(Request $request, $default, $setting, $isInterfaceWeb = false)
    {
        try {
            // Adicionar flag para indicar que é saque automático
            $request->merge(['saque_automatico' => true]);
            
            // Executar o pagamento diretamente
            switch ($default) {
                case 'cashtime':
                    $response = CashtimeTrait::requestPaymentCashtime($request);
                    break;
                case 'mercadopago':
                case 'pagarme':
                    $response = MercadoPagoTrait::requestPaymentCashtime($request);
                    break;
                case 'efi':
                    $response = EfiTrait::requestPaymentEfi($request);
                    break;
                case 'xgate':
                    $response = XgateTrait::requestPaymentXgate($request);
                    break;
                case 'witetec':
                    $response = WitetecTrait::requestPaymentWitetec($request);
                    break;
                case 'pixup':
                    $response = PixupTrait::requestPaymentPixup($request);
                    break;
                case 'bspay':
                    $response = BSPayTrait::requestPaymentBSPay($request);
                    break;
                case 'woovi':
                    $response = WooviTrait::requestSaqueWoovi($request);
                    break;
                case 'asaas':
                    $response = AsaasTrait::requestPaymentAsaas($request);
                    break;
                case 'primepay7':
                    $response = PrimePay7Trait::requestPaymentPrimePay7($request);
                    break;
                case 'xdpag':
                    $response = XDPagTrait::requestPaymentXDPag($request);
                    break;
                default:
                    return response()->json(['status' => 'error', 'message' => 'Adquirente não suportado.'], 500);
            }

            // Verificar se é um erro específico da API Pixup
            if (isset($response['data']['pixup_error']) && $response['data']['pixup_error']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $response['data']['message'],
                    'details' => $response['data']['details'] ?? null,
                    'pixup_error' => true,
                    'pixup_raw_response' => $response['data']['pixup_raw_response'] ?? null
                ], $response['status']);
            }
            
            // Padronizar resposta de saque
            if ($response['status'] === 200) {
                $standardizedResponse = ApiResponseStandardizer::standardizeWithdrawResponse(
                    $response['data'], 
                    $request->amount
                );
                return response()->json($standardizedResponse, 200);
            }
            
            return response()->json($response['data'], $response['status']);
        } catch (\Exception $e) {
            Log::error('Erro no saque automático: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao processar saque automático. Tente novamente.'
            ], 500);
        }
    }

    /**
     * Processa saque manual - cria solicitação para aprovação
     */
    private function processarSaqueManual(Request $request, $default, $isInterfaceWeb = false)
    {
        try {
            // Criar solicitação de saque para aprovação manual
            switch ($default) {
                case 'cashtime':
                    $response = CashtimeTrait::requestPaymentCashtime($request);
                    break;
                case 'mercadopago':
                case 'pagarme':
                    $response = MercadoPagoTrait::requestPaymentCashtime($request);
                    break;
                case 'efi':
                    $response = EfiTrait::requestPaymentEfi($request);
                    break;
                case 'xgate':
                    $response = XgateTrait::requestPaymentXgate($request);
                    break;
                case 'witetec':
                    $response = WitetecTrait::requestPaymentWitetec($request);
                    break;
                case 'pixup':
                    $response = PixupTrait::requestPaymentPixup($request);
                    break;
                case 'bspay':
                    $response = BSPayTrait::requestPaymentBSPay($request);
                    break;
                case 'woovi':
                    $response = WooviTrait::requestSaqueWoovi($request);
                    break;
                case 'asaas':
                    $response = AsaasTrait::requestPaymentAsaas($request);
                    break;
                case 'primepay7':
                    $response = PrimePay7Trait::requestPaymentPrimePay7($request);
                    break;
                case 'xdpag':
                    $response = XDPagTrait::requestPaymentXDPag($request);
                    break;
                default:
                    return response()->json(['status' => 'error', 'message' => 'Adquirente não suportado.'], 500);
            }

            // Verificar se é um erro específico da API Pixup
            if (isset($response['data']['pixup_error']) && $response['data']['pixup_error']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $response['data']['message'],
                    'details' => $response['data']['details'] ?? null,
                    'pixup_error' => true,
                    'pixup_raw_response' => $response['data']['pixup_raw_response'] ?? null
                ], $response['status']);
            }
            
            // Padronizar resposta de saque
            if ($response['status'] === 200) {
                $standardizedResponse = ApiResponseStandardizer::standardizeWithdrawResponse(
                    $response['data'], 
                    $request->amount
                );
                return response()->json($standardizedResponse, 200);
            }
            
            return response()->json($response['data'], $response['status']);
        } catch (\Exception $e) {
            Log::error('Erro no saque manual: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao processar solicitação de saque. Tente novamente.'
            ], 500);
        }
    }
}
