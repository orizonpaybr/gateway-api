<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adiciona índices para otimizar queries do admin dashboard
     */
    public function up(): void
    {
        // Índices para tabela users (queries de busca e filtros)
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                // Índice composto para busca (name, email, username, cpf_cnpj)
                // Nota: Índices full-text podem ser mais eficientes para LIKE queries
                if (!$this->indexExists('users', 'users_search_idx')) {
                    $table->index(['name', 'email'], 'users_search_idx');
                }
                
                // Índice composto para status e permissão (filtros comuns)
                if (!$this->indexExists('users', 'users_status_permission_idx')) {
                    $table->index(['status', 'permission'], 'users_status_permission_idx');
                }
                
                // Índice composto para created_at e status (filtros temporais)
                if (!$this->indexExists('users', 'users_created_status_idx')) {
                    $table->index(['created_at', 'status'], 'users_created_status_idx');
                }
                
                // Índice para permissão (listagem de gerentes)
                if (!$this->indexExists('users', 'users_permission_idx')) {
                    $table->index('permission', 'users_permission_idx');
                }
                
                // Índice para preferred_adquirente (queries de adquirentes)
                // Verificar se a coluna existe antes de criar o índice
                if (Schema::hasColumn('users', 'preferred_adquirente') && !$this->indexExists('users', 'users_preferred_adquirente_idx')) {
                    $table->index('preferred_adquirente', 'users_preferred_adquirente_idx');
                }
                
                // Índice para preferred_adquirente_card_billet (se existir)
                if (Schema::hasColumn('users', 'preferred_adquirente_card_billet') && !$this->indexExists('users', 'users_preferred_adq_card_billet_idx')) {
                    $table->index('preferred_adquirente_card_billet', 'users_preferred_adq_card_billet_idx');
                }
            });
        }
        
        // Índices para tabela solicitacoes (vendas 7d)
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                // Índice composto para vendas 7d (user_id + status + date)
                if (!$this->indexExists('solicitacoes', 'sol_user_status_date_7d_idx')) {
                    $table->index(['user_id', 'status', 'date'], 'sol_user_status_date_7d_idx');
                }
            });
        }
        
        // Índices para tabela adquirentes (queries de adquirentes)
        if (Schema::hasTable('adquirentes')) {
            Schema::table('adquirentes', function (Blueprint $table) {
                // Índice para status (listagem de adquirentes ativos)
                if (!$this->indexExists('adquirentes', 'adq_status_idx')) {
                    $table->index('status', 'adq_status_idx');
                }
                
                // Índice para referencia (busca por referencia)
                if (!$this->indexExists('adquirentes', 'adq_referencia_idx')) {
                    $table->index('referencia', 'adq_referencia_idx');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if ($this->indexExists('users', 'users_search_idx')) {
                    $table->dropIndex('users_search_idx');
                }
                if ($this->indexExists('users', 'users_status_permission_idx')) {
                    $table->dropIndex('users_status_permission_idx');
                }
                if ($this->indexExists('users', 'users_created_status_idx')) {
                    $table->dropIndex('users_created_status_idx');
                }
                if ($this->indexExists('users', 'users_permission_idx')) {
                    $table->dropIndex('users_permission_idx');
                }
                if ($this->indexExists('users', 'users_preferred_adquirente_idx')) {
                    $table->dropIndex('users_preferred_adquirente_idx');
                }
                if ($this->indexExists('users', 'users_preferred_adq_card_billet_idx')) {
                    $table->dropIndex('users_preferred_adq_card_billet_idx');
                }
            });
        }
        
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                $table->dropIndex('sol_user_status_date_7d_idx');
            });
        }
        
        if (Schema::hasTable('adquirentes')) {
            Schema::table('adquirentes', function (Blueprint $table) {
                $table->dropIndex('adq_status_idx');
                $table->dropIndex('adq_referencia_idx');
            });
        }
    }
    
    /**
     * Verificar se índice já existe
     */
    private function indexExists(string $table, string $index): bool
    {
        try {
            $connection = DB::connection();
            $schemaManager = $connection->getDoctrineSchemaManager();
            $doctrineTable = $schemaManager->introspectTable($table);
            return $doctrineTable->hasIndex($index);
        } catch (\Exception $e) {
            // Se não conseguir verificar via Doctrine, tentar via SQL direto
            try {
                $databaseName = DB::connection()->getDatabaseName();
                $indexes = DB::select("
                    SELECT COUNT(*) as count 
                    FROM information_schema.statistics 
                    WHERE table_schema = ? 
                    AND table_name = ? 
                    AND index_name = ?
                ", [$databaseName, $table, $index]);
                
                return isset($indexes[0]) && $indexes[0]->count > 0;
            } catch (\Exception $e2) {
                // Se tudo falhar, assume que não existe (seguro para criar)
                return false;
            }
        }
    }
};

