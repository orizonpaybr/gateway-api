<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('solicitacoes_cash_out', 'descricao_externa')) {
            Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
                $table->string('descricao_externa', 255)->nullable()->after('callback');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('solicitacoes_cash_out', 'descricao_externa')) {
            Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
                $table->dropColumn('descricao_externa');
            });
        }
    }
};
