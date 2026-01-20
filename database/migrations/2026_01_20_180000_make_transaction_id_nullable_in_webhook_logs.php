<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Torna transaction_id nullable pois alguns webhooks podem não ter transaction_id
     * (ex: webhooks de teste, eventos de sistema, etc)
     */
    public function up(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->string('transaction_id', 100)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Não podemos reverter para NOT NULL sem garantir que não há NULLs
        // Por segurança, deixamos nullable no rollback também
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE `webhook_logs` MODIFY `transaction_id` VARCHAR(100) NULL');
    }
};
