<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('cpf_cnpj')->nullable();
            $table->string('data_nascimento')->nullable();
            $table->string('telefone')->nullable();
            $table->float('saldo')->default(0.00);
            $table->integer('total_transacoes')->default(0);
            $table->string('avatar')->nullable();
            $table->boolean('status')->default(false);
            $table->timestamp('data_cadastro')->useCurrent();
            $table->string('ip_user')->nullable();
            $table->integer('transacoes_aproved')->default(0);
            $table->integer('transacoes_recused')->default(0);
            $table->decimal('valor_sacado', 10, 2)->default(0.00);
            $table->decimal('valor_saque_pendente', 10, 2)->default(0.00);
            $table->decimal('taxa_cash_in', 10, 2)->default(0.00);
            $table->decimal('taxa_cash_out', 10, 2)->default(0.00);
            $table->uuid('token')->nullable();
            $table->boolean('banido')->default(false);
            $table->string('cliente_id');
            $table->decimal('taxa_percentual', 10, 2)->default(5.00);
            $table->decimal('volume_transacional', 10, 2)->default(0.00);
            $table->decimal('valor_pago_taxa', 10, 2)->default(0.00);
            $table->string('user_id')->unique()->nullable();
            $table->string('cep')->nullable();
            $table->string('rua')->nullable();
            $table->string('estado')->nullable();
            $table->string('cidade')->nullable();
            $table->string('bairro')->nullable();
            $table->string('numero_residencia')->nullable();
            $table->string('complemento')->nullable();
            $table->string('foto_rg_frente')->nullable();
            $table->string('foto_rg_verso')->nullable();
            $table->string('selfie_rg')->nullable();
            $table->string('media_faturamento')->nullable();
            $table->string('indicador_ref')->nullable();
            $table->string('whitelisted_ip')->nullable();
            $table->string('pushcut_pixpago')->nullable();
            $table->string('twofa_secret')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
