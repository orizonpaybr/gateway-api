<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            if (! $this->indexExists('solicitacoes', 'sol_externalreference_idx')) {
                $table->index('externalreference', 'sol_externalreference_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            if ($this->indexExists('solicitacoes', 'sol_externalreference_idx')) {
                $table->dropIndex('sol_externalreference_idx');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $databaseName = DB::getDatabaseName();

        $result = DB::select(
            'SELECT COUNT(*) AS count FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$databaseName, $table, $index]
        );

        return isset($result[0]) && (int) $result[0]->count > 0;
    }
};
