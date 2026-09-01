<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Id da DEVOLUÇÃO na adquirente (reverse-pix-in). A devolução é assíncrona (Paytler
 * retorna NEW e processa em segundos, podendo FALHAR). Guardamos o id retornado no
 * createRefund pra consultar o status depois e só então finalizar o estorno
 * (REFUNDED + decremento) — evita marcar estornado e tirar do saldo antes da confirmação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            if (! Schema::hasColumn('solicitacoes', 'refund_provider_id')) {
                $table->string('refund_provider_id', 100)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            if (Schema::hasColumn('solicitacoes', 'refund_provider_id')) {
                $table->dropColumn('refund_provider_id');
            }
        });
    }
};
