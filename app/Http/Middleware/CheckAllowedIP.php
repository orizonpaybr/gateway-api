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
        
        // Recarregar usuário do banco para garantir que temos os IPs mais recentes
        // Isso evita problemas de cache quando IPs são adicionados/removidos
        $user = \App\Models\User::where('username', $user->username)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não encontrado'
            ], 404);
        }
        
        // Forçar refresh do modelo para garantir dados atualizados
        $user->refresh();
        
        // Determinar se é saque via interface web ou API
        $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';
        
        if ($isInterfaceWeb) {
            // Para requisições da interface web, usar IP do servidor configurado
            $clientIP = IPManagementTrait::getServerIPFromConfig();
        } else {
            // Para requisições de API direta, usar IP real do cliente
            $clientIP = $this->getClientIP($request);
        }

        // Usar IPManagementTrait para verificação de IPs (inclui IPs globais)
        if (!IPManagementTrait::isIPAllowed($clientIP, $user)) {
            Log::warning('[IP_CHECK] IP não autorizado para saque', [
                'user_id' => $user->user_id,
                'client_ip' => $clientIP
            ]);

            return response()->json([
                'success' => false,
                'message' => 'IP não autorizado para realizar saques'
            ], 403);
        }

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
