<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Índices para tabela solicitacoes (CRÍTICO)
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                // Índice composto para queries de dashboard (user_id + date + status)
                if (!$this->indexExists('solicitacoes', 'sol_user_date_status_idx')) {
                    $table->index(['user_id', 'date', 'status'], 'sol_user_date_status_idx');
                }
                
                // Índice para queries de valor (user_id + amount)
                if (!$this->indexExists('solicitacoes', 'sol_user_amount_idx')) {
                    $table->index(['user_id', 'amount'], 'sol_user_amount_idx');
                }
                
                // Índice para queries de período (date + amount)
                if (!$this->indexExists('solicitacoes', 'sol_date_amount_idx')) {
                    $table->index(['date', 'amount'], 'sol_date_amount_idx');
                }
                
                // Índice para status + valor (para estatísticas)
                if (!$this->indexExists('solicitacoes', 'sol_status_amount_idx')) {
                    $table->index(['status', 'amount'], 'sol_status_amount_idx');
                }
                
                // Índice para idTransaction (busca rápida)
                if (!$this->indexExists('solicitacoes', 'sol_idtransaction_idx')) {
                    $table->index(['idTransaction'], 'sol_idtransaction_idx');
                }
                
                // Índice para created_at (ordenação)
                if (!$this->indexExists('solicitacoes', 'sol_created_at_idx')) {
                    $table->index(['created_at'], 'sol_created_at_idx');
                }
                
                // Índice composto para QR Codes (user_id + idTransaction + date)
                if (!$this->indexExists('solicitacoes', 'sol_user_txid_date_idx')) {
                    $table->index(['user_id', 'idTransaction', 'date'], 'sol_user_txid_date_idx');
                }
            });
        }

        // Índices para tabela solicitacoes_cash_out (CRÍTICO)
        if (Schema::hasTable('solicitacoes_cash_out')) {
            Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
                // Índice composto para queries de dashboard
                if (!$this->indexExists('solicitacoes_cash_out', 'solco_user_date_status_idx')) {
                    $table->index(['user_id', 'date', 'status'], 'solco_user_date_status_idx');
                }
                
                // Índice para queries de valor
                if (!$this->indexExists('solicitacoes_cash_out', 'solco_user_amount_idx')) {
                    $table->index(['user_id', 'amount'], 'solco_user_amount_idx');
                }
                
                // Índice para idTransaction
                if (!$this->indexExists('solicitacoes_cash_out', 'solco_idtransaction_idx')) {
                    $table->index(['idTransaction'], 'solco_idtransaction_idx');
                }
                
                // Índice para created_at
                if (!$this->indexExists('solicitacoes_cash_out', 'solco_created_at_idx')) {
                    $table->index(['created_at'], 'solco_created_at_idx');
                }
                
                // Índice composto para QR Codes
                if (!$this->indexExists('solicitacoes_cash_out', 'solco_user_txid_date_idx')) {
                    $table->index(['user_id', 'idTransaction', 'date'], 'solco_user_txid_date_idx');
                }
            });
        }

        // Índices para tabela pix_infracoes (se existir)
        if (Schema::hasTable('pix_infracoes')) {
            Schema::table('pix_infracoes', function (Blueprint $table) {
                // Índice composto para listagem
                if (!$this->indexExists('pix_infracoes', 'pixinf_user_status_data_idx')) {
                    $table->index(['user_id', 'status', 'data_criacao'], 'pixinf_user_status_data_idx');
                }
                
                // Índice para end_to_end
                if (!$this->indexExists('pix_infracoes', 'pixinf_endtoend_idx')) {
                    $table->index(['end_to_end'], 'pixinf_endtoend_idx');
                }
                
                // Índice para transaction_id
                if (!$this->indexExists('pix_infracoes', 'pixinf_txid_idx')) {
                    $table->index(['transaction_id'], 'pixinf_txid_idx');
                }
                
                // Índice para busca
                if (!$this->indexExists('pix_infracoes', 'pixinf_descricao_idx')) {
                    $table->index(['descricao'], 'pixinf_descricao_idx');
                }
            });
        }

        // Otimizar índices existentes se necessário
        $this->optimizeExistingIndexes();
    }

    public function down(): void
    {
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                $table->dropIndex('sol_user_date_status_idx');
                $table->dropIndex('sol_user_amount_idx');
                $table->dropIndex('sol_date_amount_idx');
                $table->dropIndex('sol_status_amount_idx');
                $table->dropIndex('sol_idtransaction_idx');
                $table->dropIndex('sol_created_at_idx');
                $table->dropIndex('sol_user_txid_date_idx');
            });
        }

        if (Schema::hasTable('solicitacoes_cash_out')) {
            Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
                $table->dropIndex('solco_user_date_status_idx');
                $table->dropIndex('solco_user_amount_idx');
                $table->dropIndex('solco_idtransaction_idx');
                $table->dropIndex('solco_created_at_idx');
                $table->dropIndex('solco_user_txid_date_idx');
            });
        }

        if (Schema::hasTable('pix_infracoes')) {
            Schema::table('pix_infracoes', function (Blueprint $table) {
                $table->dropIndex('pixinf_user_status_data_idx');
                $table->dropIndex('pixinf_endtoend_idx');
                $table->dropIndex('pixinf_txid_idx');
                $table->dropIndex('pixinf_descricao_idx');
            });
        }
    }

    /**
     * Verificar se índice existe
     */
    private function indexExists(string $table, string $index): bool
    {
        try {
            $result = DB::select(
                'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [DB::getDatabaseName(), $table, $index]
            );
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Otimizar índices existentes
     */
    private function optimizeExistingIndexes()
    {
        try {
            // Analisar tabelas para otimização
            $tables = ['solicitacoes', 'solicitacoes_cash_out', 'pix_infracoes'];
            
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    // ANALYZE TABLE para otimizar estatísticas
                    DB::statement("ANALYZE TABLE {$table}");
                    
                    // OPTIMIZE TABLE para reorganizar dados
                    DB::statement("OPTIMIZE TABLE {$table}");
                }
            }
        } catch (\Throwable $e) {
            // Log erro mas não falha a migration
            \Illuminate\Support\Facades\Log::warning('Erro ao otimizar índices existentes', [
                'error' => $e->getMessage()
            ]);
        }
    }
};
