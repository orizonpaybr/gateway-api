<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha persistente de alteração de saldo (admin, afiliado, estornos, etc.).
 * Logs Laravel rotacionam — esta tabela fecha casos como o da Monica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('username', 128)->nullable();
            $table->string('field', 32); // saldo | saldo_afiliado
            $table->decimal('delta', 14, 4);
            $table->decimal('balance_before', 14, 4);
            $table->decimal('balance_after', 14, 4);
            $table->string('reason', 64);
            $table->string('source', 128)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('ref_type', 64)->nullable();
            $table->string('ref_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['reason', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_ledger_entries');
    }
};
