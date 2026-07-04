<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthEvent;
use App\Models\BlockedIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminAuthEventsController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'event_type' => 'nullable|string|max:50',
            'ip' => 'nullable|ip',
            'username' => 'nullable|string|max:255',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $query = AuthEvent::query()->orderByDesc('created_at');

        if (! empty($validated['event_type'])) {
            $query->where('event_type', $validated['event_type']);
        }

        if (! empty($validated['ip'])) {
            $query->where('ip', $validated['ip']);
        }

        if (! empty($validated['username'])) {
            $query->where('username_attempt', 'like', '%'.$validated['username'].'%');
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        $perPage = $validated['per_page'] ?? 25;

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    public function listBlockedIps(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $ips = BlockedIp::query()
            ->orderByDesc('created_at')
            ->paginate(min(100, max(10, (int) $request->input('per_page', 25))));

        return response()->json(['success' => true, 'data' => $ips]);
    }

    public function storeBlockedIp(Request $request)
    {
        $validated = $request->validate([
            'ip' => 'required|ip',
            'reason' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date',
        ]);

        $admin = $request->user() ?? $request->user_auth;

        $blocked = BlockedIp::updateOrCreate(
            ['ip' => $validated['ip']],
            [
                'reason' => $validated['reason'] ?? 'Bloqueio manual',
                'blocked_by' => $admin?->id,
                'expires_at' => $validated['expires_at'] ?? null,
            ],
        );

        Log::channel('security')->info('IP bloqueado manualmente', [
            'ip' => $blocked->ip,
            'admin_id' => $admin?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'IP bloqueado com sucesso',
            'data' => $blocked,
        ], 201);
    }

    public function destroyBlockedIp(int $id)
    {
        $blocked = BlockedIp::findOrFail($id);
        $blocked->delete();

        return response()->json([
            'success' => true,
            'message' => 'IP desbloqueado com sucesso',
        ]);
    }
}
