<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            if (!Schema::hasColumn('solicitacoes', 'end_to_end')) {
                $table->string('end_to_end', 100)->nullable()->after('idTransaction');
                $table->index('end_to_end', 'solicitacoes_end_to_end_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            if (Schema::hasColumn('solicitacoes', 'end_to_end')) {
                $table->dropIndex('solicitacoes_end_to_end_idx');
                $table->dropColumn('end_to_end');
            }
        });
    }
};
