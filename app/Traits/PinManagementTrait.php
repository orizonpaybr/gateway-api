<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

trait PinManagementTrait
{
    /**
     * Gera um PIN aleatório de 6 dígitos
     */
    public static function generatePin(): string
    {
        return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Cria um PIN para o usuário
     */
    public static function createPin(User $user, ?string $pin = null): array
    {
        try {
            if (!$pin) {
                $pin = self::generatePin();
            }

            // Validar PIN
            if (!self::isValidPin($pin)) {
                return [
                    'success' => false,
                    'message' => 'PIN deve conter exatamente 6 dígitos numéricos'
                ];
            }

            // Verificar se já existe PIN ativo
            if ($user->pin_active && $user->pin) {
                return [
                    'success' => false,
                    'message' => 'Usuário já possui PIN ativo'
                ];
            }

            $user->pin = Hash::make($pin);
            $user->pin_active = true;
            $user->pin_created_at = Carbon::now();
            $user->save();

            Log::info('[PIN_MANAGEMENT] PIN criado com sucesso', [
                'user_id' => $user->user_id,
                'pin_active' => true
            ]);

            return [
                'success' => true,
                'message' => 'PIN criado com sucesso',
                'pin' => $pin // Retorna o PIN em texto claro apenas na criação
            ];
        } catch (\Exception $e) {
            Log::error('[PIN_MANAGEMENT] Erro ao criar PIN', [
                'user_id' => $user->user_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao criar PIN'
            ];
        }
    }

    /**
     * Ativa/Desativa o PIN do usuário
     */
    public static function togglePinStatus(User $user, bool $active): array
    {
        try {
            if ($active && !$user->pin) {
                return [
                    'success' => false,
                    'message' => 'Usuário não possui PIN configurado'
                ];
            }

            $user->pin_active = $active;
            $user->save();

            Log::info('[PIN_MANAGEMENT] Status do PIN alterado', [
                'user_id' => $user->user_id,
                'pin_active' => $active
            ]);

            return [
                'success' => true,
                'message' => $active ? 'PIN ativado com sucesso' : 'PIN desativado com sucesso'
            ];
        } catch (\Exception $e) {
            Log::error('[PIN_MANAGEMENT] Erro ao alterar status do PIN', [
                'user_id' => $user->user_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao alterar status do PIN'
            ];
        }
    }

    /**
     * Altera o PIN do usuário
     */
    public static function changePin(User $user, string $currentPin, string $newPin): array
    {
        try {
            // Verificar se o PIN atual está correto
            if (!self::verifyPin($user, $currentPin)) {
                return [
                    'success' => false,
                    'message' => 'PIN atual incorreto'
                ];
            }

            // Validar novo PIN
            if (!self::isValidPin($newPin)) {
                return [
                    'success' => false,
                    'message' => 'Novo PIN deve conter exatamente 6 dígitos numéricos'
                ];
            }

            $user->pin = Hash::make($newPin);
            $user->pin_created_at = Carbon::now();
            $user->save();

            Log::info('[PIN_MANAGEMENT] PIN alterado com sucesso', [
                'user_id' => $user->user_id
            ]);

            return [
                'success' => true,
                'message' => 'PIN alterado com sucesso'
            ];
        } catch (\Exception $e) {
            Log::error('[PIN_MANAGEMENT] Erro ao alterar PIN', [
                'user_id' => $user->user_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao alterar PIN'
            ];
        }
    }

    /**
     * Verifica se o PIN está correto
     */
    public static function verifyPin(User $user, string $pin): bool
    {
        if (!$user->pin || !$user->pin_active) {
            return false;
        }

        return Hash::check($pin, $user->pin);
    }

    /**
     * Valida se o PIN tem formato correto
     */
    public static function isValidPin(string $pin): bool
    {
        return preg_match('/^\d{6}$/', $pin) === 1;
    }

    /**
     * Verifica se o usuário tem PIN ativo
     */
    public static function hasActivePin(User $user): bool
    {
        return $user->pin_active && !empty($user->pin);
    }

    /**
     * Obtém informações do PIN do usuário
     */
    public static function getPinInfo(User $user): array
    {
        $createdAt = $user->pin_created_at;
        if ($createdAt && is_string($createdAt)) {
            $createdAt = Carbon::parse($createdAt);
        }
        
        return [
            'has_pin' => !empty($user->pin),
            'is_active' => $user->pin_active,
            'created_at' => $createdAt ? $createdAt->format('d/m/Y H:i') : null,
            'days_since_creation' => $createdAt ? $createdAt->diffInDays(Carbon::now()) : null
        ];
    }

    /**
     * Remove o PIN do usuário
     */
    public static function removePin(User $user, string $currentPin): array
    {
        try {
            // Verificar se o PIN atual está correto
            if (!self::verifyPin($user, $currentPin)) {
                return [
                    'success' => false,
                    'message' => 'PIN atual incorreto'
                ];
            }

            $user->pin = null;
            $user->pin_active = false;
            $user->pin_created_at = null;
            $user->save();

            Log::info('[PIN_MANAGEMENT] PIN removido com sucesso', [
                'user_id' => $user->user_id
            ]);

            return [
                'success' => true,
                'message' => 'PIN removido com sucesso'
            ];
        } catch (\Exception $e) {
            Log::error('[PIN_MANAGEMENT] Erro ao remover PIN', [
                'user_id' => $user->user_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao remover PIN'
            ];
        }
    }
}
