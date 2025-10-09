<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BalanceLogHelper
{
    /**
     * Log específico para operações de saldo
     */
    public static function logBalanceOperation($operation, $user, $amount, $field, $details = [])
    {
        $logData = [
            'timestamp' => Carbon::now()->toISOString(),
            'operation' => $operation, // 'INCREMENT', 'DECREMENT', 'CALCULATE'
            'user_id' => $user->user_id ?? $user->id ?? 'unknown',
            'user_name' => $user->name ?? 'unknown',
            'amount' => (float) $amount,
            'field' => $field, // 'saldo', 'valor_sacado', 'valor_saque_pendente'
            'balance_before' => $user->getOriginal($field) ?? 0,
            'balance_after' => $user->$field ?? 0,
            'details' => $details,
            'function_called_from' => self::getCallerInfo(),
            'ip_address' => request()->ip() ?? 'unknown',
            'user_agent' => request()->userAgent() ?? 'unknown'
        ];

        // Log no arquivo específico
        $logMessage = self::formatLogMessage($logData);
        file_put_contents(
            storage_path('logs/analisarsaque.log'),
            $logMessage . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // Também log no Laravel log padrão para backup
        Log::channel('single')->info('BALANCE_OPERATION', $logData);
    }

    /**
     * Log específico para saques
     */
    public static function logSaqueOperation($operation, $user, $amount, $details = [])
    {
        $logData = [
            'timestamp' => Carbon::now()->toISOString(),
            'operation' => $operation, // 'SAQUE_REQUEST', 'SAQUE_APPROVE', 'SAQUE_REJECT', 'SAQUE_COMPLETE', 'SAQUE_CANCEL'
            'user_id' => $user->user_id ?? $user->id ?? 'unknown',
            'user_name' => $user->name ?? 'unknown',
            'amount' => (float) $amount,
            'balance_before' => $user->saldo ?? 0,
            'balance_after' => $user->fresh()->saldo ?? 0,
            'details' => $details,
            'function_called_from' => self::getCallerInfo(),
            'ip_address' => request()->ip() ?? 'unknown',
            'user_agent' => request()->userAgent() ?? 'unknown'
        ];

        // Log no arquivo específico
        $logMessage = self::formatSaqueLogMessage($logData);
        file_put_contents(
            storage_path('logs/analisarsaque.log'),
            $logMessage . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // Também log no Laravel log padrão para backup
        Log::channel('single')->info('SAQUE_OPERATION', $logData);
    }

    /**
     * Log para depósitos
     */
    public static function logDepositOperation($operation, $user, $amount, $details = [])
    {
        $logData = [
            'timestamp' => Carbon::now()->toISOString(),
            'operation' => $operation, // 'DEPOSIT_CREDIT', 'DEPOSIT_PROCESS'
            'user_id' => $user->user_id ?? $user->id ?? 'unknown',
            'user_name' => $user->name ?? 'unknown',
            'amount' => (float) $amount,
            'balance_before' => $user->saldo ?? 0,
            'balance_after' => $user->fresh()->saldo ?? 0,
            'details' => $details,
            'function_called_from' => self::getCallerInfo(),
            'ip_address' => request()->ip() ?? 'unknown',
            'user_agent' => request()->userAgent() ?? 'unknown'
        ];

        // Log no arquivo específico
        $logMessage = self::formatDepositLogMessage($logData);
        file_put_contents(
            storage_path('logs/analisarsaque.log'),
            $logMessage . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // Também log no Laravel log padrão para backup
        Log::channel('single')->info('DEPOSIT_OPERATION', $logData);
    }

    /**
     * Log para cálculo de saldo
     */
    public static function logBalanceCalculation($user, $calculatedBalance, $details = [])
    {
        $logData = [
            'timestamp' => Carbon::now()->toISOString(),
            'operation' => 'BALANCE_CALCULATION',
            'user_id' => $user->user_id ?? $user->id ?? 'unknown',
            'user_name' => $user->name ?? 'unknown',
            'balance_before' => $user->saldo ?? 0,
            'calculated_balance' => (float) $calculatedBalance,
            'balance_after' => $user->fresh()->saldo ?? 0,
            'details' => $details,
            'function_called_from' => self::getCallerInfo(),
            'ip_address' => request()->ip() ?? 'unknown',
            'user_agent' => request()->userAgent() ?? 'unknown'
        ];

        // Log no arquivo específico
        $logMessage = self::formatCalculationLogMessage($logData);
        file_put_contents(
            storage_path('logs/analisarsaque.log'),
            $logMessage . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // Também log no Laravel log padrão para backup
        Log::channel('single')->info('BALANCE_CALCULATION', $logData);
    }

    /**
     * Obter informações do chamador da função
     */
    private static function getCallerInfo()
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        
        foreach ($backtrace as $trace) {
            if (isset($trace['class']) && 
                !str_contains($trace['class'], 'BalanceLogHelper') &&
                !str_contains($trace['class'], 'Helper')) {
                return [
                    'class' => $trace['class'] ?? 'unknown',
                    'function' => $trace['function'] ?? 'unknown',
                    'file' => basename($trace['file'] ?? 'unknown'),
                    'line' => $trace['line'] ?? 'unknown'
                ];
            }
        }
        
        return [
            'class' => 'unknown',
            'function' => 'unknown',
            'file' => 'unknown',
            'line' => 'unknown'
        ];
    }

    /**
     * Formatar mensagem de log para operações de saldo
     */
    private static function formatLogMessage($logData)
    {
        return sprintf(
            "[%s] %s | User: %s (%s) | Amount: R$ %.2f | Field: %s | Balance: %.2f → %.2f | From: %s::%s | IP: %s",
            $logData['timestamp'],
            $logData['operation'],
            $logData['user_name'],
            $logData['user_id'],
            $logData['amount'],
            $logData['field'],
            $logData['balance_before'],
            $logData['balance_after'],
            $logData['function_called_from']['class'],
            $logData['function_called_from']['function'],
            $logData['ip_address']
        );
    }

    /**
     * Formatar mensagem de log para saques
     */
    private static function formatSaqueLogMessage($logData)
    {
        return sprintf(
            "[%s] SAQUE_%s | User: %s (%s) | Amount: R$ %.2f | Balance: %.2f → %.2f | From: %s::%s | IP: %s | Details: %s",
            $logData['timestamp'],
            $logData['operation'],
            $logData['user_name'],
            $logData['user_id'],
            $logData['amount'],
            $logData['balance_before'],
            $logData['balance_after'],
            $logData['function_called_from']['class'],
            $logData['function_called_from']['function'],
            $logData['ip_address'],
            json_encode($logData['details'])
        );
    }

    /**
     * Formatar mensagem de log para depósitos
     */
    private static function formatDepositLogMessage($logData)
    {
        return sprintf(
            "[%s] DEPOSIT_%s | User: %s (%s) | Amount: R$ %.2f | Balance: %.2f → %.2f | From: %s::%s | IP: %s | Details: %s",
            $logData['timestamp'],
            $logData['operation'],
            $logData['user_name'],
            $logData['user_id'],
            $logData['amount'],
            $logData['balance_before'],
            $logData['balance_after'],
            $logData['function_called_from']['class'],
            $logData['function_called_from']['function'],
            $logData['ip_address'],
            json_encode($logData['details'])
        );
    }

    /**
     * Formatar mensagem de log para cálculos
     */
    private static function formatCalculationLogMessage($logData)
    {
        return sprintf(
            "[%s] %s | User: %s (%s) | Calculated: %.2f | Balance: %.2f → %.2f | From: %s::%s | IP: %s | Details: %s",
            $logData['timestamp'],
            $logData['operation'],
            $logData['user_name'],
            $logData['user_id'],
            $logData['calculated_balance'],
            $logData['balance_before'],
            $logData['balance_after'],
            $logData['function_called_from']['class'],
            $logData['function_called_from']['function'],
            $logData['ip_address'],
            json_encode($logData['details'])
        );
    }

    /**
     * Limpar logs antigos (manter apenas últimos 30 dias)
     */
    public static function cleanOldLogs()
    {
        $logFile = storage_path('logs/analisarsaque.log');
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            $cutoffDate = Carbon::now()->subDays(30);
            $filteredLines = [];
            
            foreach ($lines as $line) {
                if (preg_match('/\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z)\]/', $line, $matches)) {
                    $logDate = Carbon::parse($matches[1]);
                    if ($logDate->gte($cutoffDate)) {
                        $filteredLines[] = $line;
                    }
                }
            }
            
            file_put_contents($logFile, implode(PHP_EOL, $filteredLines) . PHP_EOL);
        }
    }
}

