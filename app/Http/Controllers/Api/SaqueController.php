<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Log, Cache};
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
        
        // Cache para configurações do app (TTL: 5 minutos)
        $setting = Cache::remember('app_settings', 300, function () {
            return App::first();
        });
        
        if (!$setting) {
            return response()->json(['status' => 'error', 'message' => 'Configurações do aplicativo não encontradas.'], 500);
        }

        // Cache para adquirente padrão do usuário (TTL: 10 minutos)
        $cacheKey = "user_default_acquirer_{$user->user_id}";
        $default = Cache::remember($cacheKey, 600, function () use ($user) {
            return Helper::adquirenteDefault($user->user_id);
        });
        
        if (!$default) {
            return response()->json(['status' => 'error', 'message' => 'Nenhum adquirente configurado.'], 500);
        }

        // Verificar se o saque está bloqueado para este usuário (sem query adicional)
        if ($user->saque_bloqueado ?? false) {
            Log::warning('Tentativa de saque bloqueado', [
                'user_id' => $user->id,
                'username' => $user->username,
                'ip' => $request->ip()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Saque bloqueado para este usuário. Entre em contato com o suporte.'
            ], 403);
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
        // Adicionar flag para indicar que é saque automático
        $request->merge(['saque_automatico' => true]);
        
        return $this->processarSaque($request, $default, true);
    }

    /**
     * Processa saque manual - cria solicitação para aprovação
     */
    private function processarSaqueManual(Request $request, $default, $isInterfaceWeb = false)
    {
        return $this->processarSaque($request, $default, false);
    }

    /**
     * Processa saque
     * 
     * @param Request $request
     * @param string $default
     * @param bool $isAutomatico
     * @return \Illuminate\Http\JsonResponse
     */
    private function processarSaque(Request $request, string $default, bool $isAutomatico = false)
    {
        try {
            // Executar o pagamento baseado no adquirente
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
            $tipo = $isAutomatico ? 'automático' : 'manual';
            Log::error("Erro no saque {$tipo}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'adquirente' => $default,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => "Erro ao processar saque {$tipo}. Tente novamente."
            ], 500);
        }
    }
}
