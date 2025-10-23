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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('gerente_id')->nullable();
            $table->string('solicitacao_id')->nullable();
            $table->decimal('comission_value', 10, 2)->default(0.00);
            $table->decimal('transaction_percent', 5, 2)->default(0.00);
            $table->decimal('comission_percent', 5, 2)->default(0.00);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'gerente_id',
                'solicitacao_id', 
                'comission_value',
                'transaction_percent',
                'comission_percent'
            ]);
        });
    }
};
