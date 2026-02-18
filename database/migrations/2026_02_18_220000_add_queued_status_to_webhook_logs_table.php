<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Adiciona QUEUED ao ENUM e troca o default para QUEUED
        DB::statement("ALTER TABLE `webhook_logs` MODIFY COLUMN `status` ENUM('PROCESSING','QUEUED','PROCESSED','FAILED') NOT NULL DEFAULT 'QUEUED'");
    }

    public function down(): void
    {
        // Converte QUEUED → PROCESSING antes de remover o valor do ENUM
        DB::statement("UPDATE `webhook_logs` SET `status` = 'PROCESSING' WHERE `status` = 'QUEUED'");
        DB::statement("ALTER TABLE `webhook_logs` MODIFY COLUMN `status` ENUM('PROCESSING','PROCESSED','FAILED') NOT NULL DEFAULT 'PROCESSING'");
    }
};
