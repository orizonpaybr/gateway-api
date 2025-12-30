<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adiciona índice em saldo para otimizar ORDER BY saldo DESC/ASC
     * usado em listagem de carteiras e TOP 3 usuários
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Índice para ordenação por saldo (usado em carteiras e TOP 3)
            if (!$this->indexExists('users', 'idx_users_saldo')) {
                $table->index('saldo', 'idx_users_saldo');
            }
            
            // Índice composto para busca e ordenação (otimiza queries com filtro + ordenação)
            if (!$this->indexExists('users', 'idx_users_saldo_status')) {
                $table->index(['saldo', 'status'], 'idx_users_saldo_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if ($this->indexExists('users', 'idx_users_saldo_status')) {
                $table->dropIndex('idx_users_saldo_status');
            }
            if ($this->indexExists('users', 'idx_users_saldo')) {
                $table->dropIndex('idx_users_saldo');
            }
        });
    }

    /**
     * Verifica se índice já existe (compatível com MySQL e SQLite)
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driverName = $connection->getDriverName();
        
        // Para MySQL/MariaDB
        if (in_array($driverName, ['mysql', 'mariadb'])) {
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
        
        // Para SQLite
        if ($driverName === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list({$table})");
            foreach ($indexes as $idx) {
                if ($idx->name === $index) {
                    return true;
                }
            }
            return false;
        }
        
        // Fallback: tentar criar e capturar erro
        try {
            $connection->statement("SHOW INDEX FROM {$table} WHERE Key_name = '{$index}'");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
};

