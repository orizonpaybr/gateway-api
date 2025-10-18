<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Índices para tabela solicitacoes
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                // Índice composto para queries de dashboard
                if (!$this->indexExists('solicitacoes', 'sol_user_date_status_idx')) {
                    $table->index(['user_id', 'date', 'status'], 'sol_user_date_status_idx');
                }
                
                // Índice para queries de valor
                if (!$this->indexExists('solicitacoes', 'sol_user_amount_idx')) {
                    $table->index(['user_id', 'amount'], 'sol_user_amount_idx');
                }
                
                // Índice para queries de período
                if (!$this->indexExists('solicitacoes', 'sol_date_amount_idx')) {
                    $table->index(['date', 'amount'], 'sol_date_amount_idx');
                }
                
                // Índice para status + valor (para estatísticas)
                if (!$this->indexExists('solicitacoes', 'sol_status_amount_idx')) {
                    $table->index(['status', 'amount'], 'sol_status_amount_idx');
                }
            });
        }

        // Índices para tabela solicitacoes_cash_out
        if (Schema::hasTable('solicitacoes_cash_out')) {
            Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
                if (!$this->indexExists('solicitacoes_cash_out', 'solco_user_date_status_idx')) {
                    $table->index(['user_id', 'date', 'status'], 'solco_user_date_status_idx');
                }
                
                if (!$this->indexExists('solicitacoes_cash_out', 'solco_user_amount_idx')) {
                    $table->index(['user_id', 'amount'], 'solco_user_amount_idx');
                }
            });
        }

        // Índices para tabela qr_codes
        if (Schema::hasTable('qr_codes')) {
            Schema::table('qr_codes', function (Blueprint $table) {
                if (!$this->indexExists('qr_codes', 'qrc_user_created_idx')) {
                    $table->index(['user_id', 'created_at'], 'qrc_user_created_idx');
                }
                
                if (!$this->indexExists('qr_codes', 'qrc_expires_at_idx')) {
                    $table->index(['expires_at'], 'qrc_expires_at_idx');
                }
                
                if (!$this->indexExists('qr_codes', 'qrc_user_tipo_idx')) {
                    $table->index(['user_id', 'tipo'], 'qrc_user_tipo_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                $table->dropIndex('sol_user_date_status_idx');
                $table->dropIndex('sol_user_amount_idx');
                $table->dropIndex('sol_date_amount_idx');
                $table->dropIndex('sol_status_amount_idx');
            });
        }

        if (Schema::hasTable('solicitacoes_cash_out')) {
            Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
                $table->dropIndex('solco_user_date_status_idx');
                $table->dropIndex('solco_user_amount_idx');
            });
        }

        if (Schema::hasTable('qr_codes')) {
            Schema::table('qr_codes', function (Blueprint $table) {
                $table->dropIndex('qrc_user_created_idx');
                $table->dropIndex('qrc_expires_at_idx');
                $table->dropIndex('qrc_user_tipo_idx');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $result = \Illuminate\Support\Facades\DB::select(
                'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [\Illuminate\Support\Facades\DB::getDatabaseName(), $table, $index]
            );
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
