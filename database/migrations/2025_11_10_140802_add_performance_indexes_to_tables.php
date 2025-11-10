<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adiciona índices para melhorar performance de queries frequentes
     */
    public function up(): void
    {
        // Índices para tabela solicitacoes (depósitos)
        Schema::table('solicitacoes', function (Blueprint $table) {
            // Índice composto para queries de vendas por usuário e status
            if (!$this->indexExists('solicitacoes', 'idx_solicitacoes_user_status_date')) {
                $table->index(['user_id', 'status', 'date'], 'idx_solicitacoes_user_status_date');
            }
            
            // Índice para status (usado em filtros frequentes)
            if (!$this->indexExists('solicitacoes', 'idx_solicitacoes_status')) {
                $table->index('status', 'idx_solicitacoes_status');
            }
            
            // Índice para data (usado em filtros de período)
            if (!$this->indexExists('solicitacoes', 'idx_solicitacoes_date')) {
                $table->index('date', 'idx_solicitacoes_date');
            }
        });

        // Índices para tabela solicitacoes_cash_out (saques)
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            // Índice composto para queries de saques por usuário e status
            if (!$this->indexExists('solicitacoes_cash_out', 'idx_solicitacoes_cash_out_user_status_date')) {
                $table->index(['user_id', 'status', 'date'], 'idx_solicitacoes_cash_out_user_status_date');
            }
            
            // Índice para status (usado em filtros frequentes)
            if (!$this->indexExists('solicitacoes_cash_out', 'idx_solicitacoes_cash_out_status')) {
                $table->index('status', 'idx_solicitacoes_cash_out_status');
            }
            
            // Índice para data (usado em filtros de período)
            if (!$this->indexExists('solicitacoes_cash_out', 'idx_solicitacoes_cash_out_date')) {
                $table->index('date', 'idx_solicitacoes_cash_out_date');
            }
        });

        // Índices para tabela users
        Schema::table('users', function (Blueprint $table) {
            // Índice para user_id (usado em joins e buscas)
            if (!$this->indexExists('users', 'idx_users_user_id')) {
                $table->index('user_id', 'idx_users_user_id');
            }
            
            // Índice composto para status e created_at (usado em listagens)
            if (!$this->indexExists('users', 'idx_users_status_created')) {
                $table->index(['status', 'created_at'], 'idx_users_status_created');
            }
        });

        // Índices para tabela users_key
        Schema::table('users_key', function (Blueprint $table) {
            // Índice para user_id (usado em buscas frequentes)
            if (!$this->indexExists('users_key', 'idx_users_key_user_id')) {
                $table->index('user_id', 'idx_users_key_user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->dropIndex('idx_solicitacoes_user_status_date');
            $table->dropIndex('idx_solicitacoes_status');
            $table->dropIndex('idx_solicitacoes_date');
        });

        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->dropIndex('idx_solicitacoes_cash_out_user_status_date');
            $table->dropIndex('idx_solicitacoes_cash_out_status');
            $table->dropIndex('idx_solicitacoes_cash_out_date');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_user_id');
            $table->dropIndex('idx_users_status_created');
        });

        Schema::table('users_key', function (Blueprint $table) {
            $table->dropIndex('idx_users_key_user_id');
        });
    }

    /**
     * Verificar se um índice já existe
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        
        $result = $connection->select(
            "SELECT COUNT(*) as count 
             FROM information_schema.statistics 
             WHERE table_schema = ? 
             AND table_name = ? 
             AND index_name = ?",
            [$databaseName, $table, $index]
        );
        
        return $result[0]->count > 0;
    }
};
