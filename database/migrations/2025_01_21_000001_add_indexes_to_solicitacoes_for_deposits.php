<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                // Índice para busca por data (usado em filtros de período)
                if (!$this->indexExists('solicitacoes', 'sol_date_idx')) {
                    $table->index('date', 'sol_date_idx');
                }

                // Índice composto para (status, date) - usado em queries de estatísticas
                if (!$this->indexExists('solicitacoes', 'sol_status_date_idx')) {
                    $table->index(['status', 'date'], 'sol_status_date_idx');
                }

                // Índice para idTransaction (já existe unique, mas pode ter índice adicional para busca LIKE)
                // O unique já cria um índice, mas vamos garantir que existe
                if (!$this->indexExists('solicitacoes', 'solicitacoes_idtransaction_unique')) {
                    // Se não existe o unique, criar índice normal
                    if (!$this->indexExists('solicitacoes', 'sol_idtransaction_idx')) {
                        $table->index('idTransaction', 'sol_idtransaction_idx');
                    }
                }

                // Índice para user_id (se não existe)
                if (!$this->indexExists('solicitacoes', 'solicitacoes_user_id_foreign')) {
                    // Verificar se já existe algum índice em user_id
                    if (!$this->indexExists('solicitacoes', 'sol_user_id_idx')) {
                        $table->index('user_id', 'sol_user_id_idx');
                    }
                }

                // Índice composto para (user_id, date) - usado em queries de depósitos por usuário
                if (!$this->indexExists('solicitacoes', 'sol_user_id_date_idx')) {
                    $table->index(['user_id', 'date'], 'sol_user_id_date_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                if ($this->indexExists('solicitacoes', 'sol_date_idx')) {
                    $table->dropIndex('sol_date_idx');
                }
                if ($this->indexExists('solicitacoes', 'sol_status_date_idx')) {
                    $table->dropIndex('sol_status_date_idx');
                }
                if ($this->indexExists('solicitacoes', 'sol_idtransaction_idx')) {
                    $table->dropIndex('sol_idtransaction_idx');
                }
                if ($this->indexExists('solicitacoes', 'sol_user_id_idx')) {
                    $table->dropIndex('sol_user_id_idx');
                }
                if ($this->indexExists('solicitacoes', 'sol_user_id_date_idx')) {
                    $table->dropIndex('sol_user_id_date_idx');
                }
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $dbName = DB::getDatabaseName();
            $result = DB::select(
                'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$dbName, $table, $index]
            );
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }
};

