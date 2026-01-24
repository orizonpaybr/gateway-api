<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adiciona coluna end_to_end para armazenar o identificador único
     * da transação PIX retornado pela TREEAL/BACEN.
     */
    public function up(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            // EndToEndId é o identificador único da transação PIX no BACEN
            // Formato: E[ISPB_PAGADOR][DATA][IDENTIFICADOR] - 32 caracteres
            $table->string('end_to_end', 50)->nullable()->after('idTransaction');
            
            // Índice para consultas por end_to_end
            $table->index('end_to_end', 'cashout_end_to_end_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->dropIndex('cashout_end_to_end_idx');
            $table->dropColumn('end_to_end');
        });
    }
};
