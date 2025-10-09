<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Traits\IPManagementTrait;

class CheckAllowedIP
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Verificar se o usuário está autenticado via token (definido pelo CheckTokenAndSecret)
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }
        
        // Debug: Log dos dados da requisição
        Log::info('[IP_CHECK] Debug da requisição no middleware', [
            'user_id' => $user->user_id,
            'all_input' => $request->all(),
            'baasPostbackUrl' => $request->input('baasPostbackUrl'),
            'method' => $request->method(),
            'url' => $request->url()
        ]);
        
        // Determinar se é saque via interface web ou API
        $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';
        
        if ($isInterfaceWeb) {
            // Para requisições da interface web, usar IP do servidor configurado
            $clientIP = IPManagementTrait::getServerIPFromConfig();
            Log::info('[IP_CHECK] Saque via interface web - usando IP do servidor configurado', [
                'user_id' => $user->user_id,
                'client_ip' => $clientIP,
                'baasPostbackUrl' => $request->input('baasPostbackUrl')
            ]);
        } else {
            // Para requisições de API direta, usar IP real do cliente
            $clientIP = $this->getClientIP($request);
            Log::info('[IP_CHECK] Saque via API direta - usando IP do cliente', [
                'user_id' => $user->user_id,
                'client_ip' => $clientIP,
                'baasPostbackUrl' => $request->input('baasPostbackUrl')
            ]);
        }

        // Usar IPManagementTrait para verificação de IPs (inclui IPs globais)
        if (!IPManagementTrait::isIPAllowed($clientIP, $user)) {
            Log::warning('[IP_CHECK] IP não autorizado para saque', [
                'user_id' => $user->user_id,
                'client_ip' => $clientIP,
                'is_interface_web' => $isInterfaceWeb
            ]);

            return response()->json([
                'success' => false,
                'message' => 'IP não autorizado para realizar saques',
                'client_ip' => $clientIP
            ], 403);
        }

        Log::info('[IP_CHECK] IP autorizado para saque', [
            'user_id' => $user->user_id,
            'client_ip' => $clientIP,
            'is_interface_web' => $isInterfaceWeb
        ]);

        return $next($request);
    }

    /**
     * Obtém o IP real do cliente (usando IPManagementTrait)
     */
    private function getClientIP(Request $request): string
    {
        return IPManagementTrait::getClientIP();
    }
}
