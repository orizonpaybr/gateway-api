<?php

namespace App\Http\Middleware;

use App\Constants\AuthEventType;
use App\Models\BlockedIp;
use App\Services\AuthAuditService;
use App\Services\IpReputationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckIpReputation
{
    public function __construct(
        private IpReputationService $reputation,
        private AuthAuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if (BlockedIp::isBlocked($ip)) {
            $this->audit->log(AuthEventType::IP_BLOCKED, $request, null, $request->input('username'));

            return response()->json([
                'success' => false,
                'message' => 'Acesso negado deste endereço IP.',
            ], 403);
        }

        if ($this->reputation->isSuspicious($ip)) {
            $this->audit->log(AuthEventType::IP_BLOCKED, $request, null, $request->input('username'), [
                'source' => 'reputation',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Acesso negado deste endereço IP.',
            ], 403);
        }

        return $next($request);
    }
}
