<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('depositos_api', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('id_externo');
            $table->decimal('valor', 10, 2);
            $table->string('cliente_nome');
            $table->string('cliente_documento');
            $table->string('cliente_email');
            $table->string('cliente_telefone');
            $table->timestamp('data_real')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->enum('status', ['aguardando', 'aprovado', 'rejeitado'])->default('aguardando');
            $table->longText('qrcode');
            $table->longText('pixcopiaecola');
            $table->string('idTransaction');
            $table->string('callback_url')->nullable();
            $table->string('adquirente_ref')->nullable();
            $table->decimal('taxa_cash_in', 10, 2);
            $table->decimal('deposito_liquido', 10, 2);
            $table->decimal('taxa_pix_cash_in_adquirente', 10, 2);
            $table->decimal('taxa_pix_cash_in_valor_fixo', 10, 2);
            $table->string('executor_ordem')->nullable();
            $table->string('descricao_transacao')->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depositos_api');
    }
};