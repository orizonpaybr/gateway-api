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
                if (!Schema::hasColumn('solicitacoes', 'descricao_normalizada')) {
                    // adiciona sem depender da posição de uma coluna que pode não existir
                    $table->text('descricao_normalizada')->nullable();
                }
            });

            // Índice composto (user_id, status, created_at)
            if (!$this->indexExistsUsingInformationSchema('solicitacoes', 'sol_user_status_created_idx')) {
                Schema::table('solicitacoes', function (Blueprint $table) {
                    $table->index(['user_id', 'status', 'created_at'], 'sol_user_status_created_idx');
                });
            }

            // Índices para busca
            if (Schema::hasColumn('solicitacoes', 'transaction_id')) {
                if (!$this->indexExistsUsingInformationSchema('solicitacoes', 'sol_transaction_id_idx')) {
                    Schema::table('solicitacoes', function (Blueprint $table) {
                        $table->index(['transaction_id'], 'sol_transaction_id_idx');
                    });
                }
            }
            if (Schema::hasColumn('solicitacoes', 'codigo_autenticacao')) {
                if (!$this->indexExistsUsingInformationSchema('solicitacoes', 'sol_codigo_autenticacao_idx')) {
                    Schema::table('solicitacoes', function (Blueprint $table) {
                        $table->index(['codigo_autenticacao'], 'sol_codigo_autenticacao_idx');
                    });
                }
            }
            if (Schema::hasColumn('solicitacoes', 'descricao_normalizada') && !$this->indexExistsUsingInformationSchema('solicitacoes', 'sol_desc_norm_idx')) {
                // Para colunas TEXT/BLOB, é necessário especificar um comprimento prefixado no índice
                // Usando 255 caracteres como prefixo (máximo comum para índices MySQL)
                DB::statement('ALTER TABLE `solicitacoes` ADD INDEX `sol_desc_norm_idx` (`descricao_normalizada`(255))');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('solicitacoes')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                if (Schema::hasColumn('solicitacoes', 'descricao_normalizada')) {
                    $table->dropIndex('sol_desc_norm_idx');
                    $table->dropColumn('descricao_normalizada');
                }
                $table->dropIndex('sol_user_status_created_idx');
                $table->dropIndex('sol_transaction_id_idx');
                $table->dropIndex('sol_codigo_autenticacao_idx');
            });
        }
    }

    private function indexExistsUsingInformationSchema(string $table, string $index): bool
    {
        try {
            $dbName = DB::getDatabaseName();
            $result = DB::select(
                'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$dbName, $table, $index]
            );
            return !empty($result);
        } catch (\Throwable $e) {
            return false; // se não conseguir checar, tentamos criar e deixamos o banco acusar se já existe
        }
    }
};


