<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Log, Cache};
use App\Enums\PixKeyType;
use App\Traits\IPManagementTrait;
use App\Models\User;
use App\Models\App;
use App\Helpers\Helper;
use App\Models\Adquirente;
use App\Models\SolicitacoesCashOut;
use App\Helpers\ApiResponseStandardizer;
use App\Services\PixAcquirer\PixAcquirerManager;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Jobs\ClientWebhookDispatchJob;

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

        // Verificar saldo disponível = saldo principal + saldo de afiliados (considerando valores em mediação)
        $saldoDisponivel = (float) ($user->saldo ?? 0) + (float) ($user->saldo_afiliado ?? 0);
        
        // Calcular valores bloqueados em mediação
        $valoresEmMediacao = \App\Models\Solicitacoes::where('user_id', $user->id)
            ->where('status', 'MEDIATION')
            ->sum('deposito_liquido');
        
        $saldoRealDisponivel = $saldoDisponivel - (float) $valoresEmMediacao;
        
        if ($saldoRealDisponivel < (float)$request->amount) {
            $this->dispatchWebhookFalhaSaldoCoratri(
                $request,
                $user,
                (float) $request->amount,
                $saldoRealDisponivel
            );
            return response()->json([
                'status' => 'error',
                'message' => 'Não foi possível sacar, entre em contato com o suporte.'
            ], 401);
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

        // Limite máximo por saque PIX (ex.: R$ 100.000,00)
        $limiteMaximoSaque = (float) config('saque.limite_maximo_por_saque', 100000);
        if ((float) $request->amount > $limiteMaximoSaque) {
            return response()->json([
                'status' => 'error',
                'message' => 'Valor acima do limite máximo por saque de R$ ' . number_format($limiteMaximoSaque, 2, ',', '.') . '.',
            ], 422);
        }

        $processarAutomatico = \App\Helpers\WithdrawalConfigResolver::isAutomatico($user, $setting, (float) $request->amount);

        if ($processarAutomatico) {
            return $this->processarSaqueAutomatico($request, $default, $setting, $isInterfaceWeb);
        }

        return $this->processarSaqueManual($request, $default, $isInterfaceWeb);
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
            if (strtolower($default) === 'pagarme') {
                return response()->json(['status' => 'error', 'message' => 'Adquirente não suportado para saques.'], 500);
            }

            $acquirerService = app(PixAcquirerManager::class)->resolve($default);
            if (!$acquirerService->isActive()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Integração PIX temporariamente indisponível.'
                ], 503);
            }

            // Estrutura pronta para próxima adquirente: integração concreta será plugada no service.
            return response()->json([
                'status' => 'error',
                'message' => 'Integração da adquirente PIX ainda não implementada.'
            ], 503);
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

    /**
     * Dispara webhook de falha por saldo insuficiente na conta Coratri do usuário.
     * Só usa saldo da Coratri (conta do usuário); nunca expõe dados do AdquirentePIX/conta master.
     * Só é chamado quando a falha é por saldo Coratri; em falhas do AdquirentePIX mantemos mensagem genérica.
     */
    private function dispatchWebhookFalhaSaldoCoratri(Request $request, User $user, float $amountRequested, float $saldoCoratriDisponivel): void
    {
        $callbackUrl = $request->filled('baasPostbackUrl') && $request->baasPostbackUrl !== 'web'
            ? $request->baasPostbackUrl
            : null;
        if (!$callbackUrl) {
            return;
        }

        $idTransaction = 'PAYOUT_API_' . preg_replace('/[^a-zA-Z0-9]/', '', Str::uuid()->toString());
        $messageWebhook = 'Saldo insuficiente. Você tentou sacar R$ ' . number_format($amountRequested, 2, ',', '.')
            . ', seu saldo disponível é R$ ' . number_format($saldoCoratriDisponivel, 2, ',', '.') . '.';

        try {
            SolicitacoesCashOut::create([
                'user_id'             => $user->username,
                'externalreference'   => $idTransaction,
                'amount'              => $amountRequested,
                'beneficiaryname'     => $request->input('beneficiary_name') ?? $user->name ?? $user->username ?? 'N/A',
                'beneficiarydocument'  => $request->input('pixKey', ''),
                'pix'                 => $request->input('pixKey', ''),
                'pixkey'              => $request->input('pixKeyType', 'cpf'),
                'date'                => Carbon::now(),
                'status'              => 'FAILED',
                'type'                => 'PIX',
                'idTransaction'       => $idTransaction,
                'taxa_cash_out'       => 0,
                'cash_out_liquido'    => $amountRequested,
                'callback'            => $callbackUrl,
            ]);

            ClientWebhookDispatchJob::dispatch(
                $callbackUrl,
                $idTransaction,
                'FAILED',
                $amountRequested,
                now()->toIso8601String(),
                ['typeTransaction' => 'PIX_OUT', 'sender' => ['user_id' => $user->username]],
                $messageWebhook
            );
        } catch (\Throwable $e) {
            Log::warning('SaqueController::dispatchWebhookFalhaSaldoCoratri - Erro ao criar registro ou disparar webhook', [
                'error' => $e->getMessage(),
                'user_id' => $user->username,
            ]);
        }
    }

}
