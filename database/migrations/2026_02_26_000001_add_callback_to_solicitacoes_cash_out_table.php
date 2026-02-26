<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->string('callback')->nullable()->after('descricao_transacao');
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->dropColumn('callback');
        });
    }
};
