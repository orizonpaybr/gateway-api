<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referência externa do integrador (número de pedido do cliente).
 *
 * Permite ao cliente enviar o próprio número de pedido na criação do PIX e
 * consultar por ele — inclusive quando um erro de rede impede receber nosso ID.
 *
 * O índice único (user_id, client_reference) garante IDEMPOTÊNCIA no nível do
 * banco: um retry com a mesma referência não cria depósito duplicado (MySQL
 * permite múltiplos NULL, então só vale para referências efetivamente informadas).
 *
 * Coluna separada de propósito: a `externalreference` interna (UUID) continua
 * sendo a chave usada na consulta pública não autenticada — mantê-la
 * auto-gerada preserva a impossibilidade de enumerar transações alheias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->string('client_reference', 80)->nullable()->after('externalreference');
            $table->unique(['user_id', 'client_reference'], 'solicitacoes_user_client_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->dropUnique('solicitacoes_user_client_ref_unique');
            $table->dropColumn('client_reference');
        });
    }
};
