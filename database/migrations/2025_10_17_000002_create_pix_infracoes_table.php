<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pix_infracoes')) {
            Schema::create('pix_infracoes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('user_id', 191);
                $table->string('transaction_id', 191)->nullable();
                $table->enum('status', ['PENDENTE','EM_ANALISE','RESOLVIDA','CANCELADA','CHARGEBACK','MEDIATION','DISPUTE'])->default('PENDENTE');
                $table->string('tipo', 100)->default('pix');
                $table->text('descricao')->nullable();
                $table->text('descricao_normalizada')->nullable();
                $table->decimal('valor', 15, 2)->default(0);
                $table->string('end_to_end', 100)->nullable();
                $table->dateTime('data_criacao')->nullable();
                $table->dateTime('data_limite')->nullable();
                $table->text('detalhes')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status', 'data_criacao'], 'pixinf_user_status_data_idx');
                $table->index(['end_to_end'], 'pixinf_endtoend_idx');
                $table->index(['transaction_id'], 'pixinf_txid_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pix_infracoes');
    }
};


