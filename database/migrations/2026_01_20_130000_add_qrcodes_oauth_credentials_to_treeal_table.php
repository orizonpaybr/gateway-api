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
        Schema::table('treeal', function (Blueprint $table) {
            // Credenciais OAuth2 específicas para QR Codes API
            $table->string('qrcodes_client_id')->nullable()->after('client_secret')->comment('Client ID para QR Codes API OAuth2');
            $table->string('qrcodes_client_secret')->nullable()->after('qrcodes_client_id')->comment('Client Secret para QR Codes API OAuth2');
            
            // Renomear campos existentes para deixar claro que são para Accounts API
            // (não vamos renomear para não quebrar código existente, mas vamos adicionar comentários)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('treeal', function (Blueprint $table) {
            $table->dropColumn(['qrcodes_client_id', 'qrcodes_client_secret']);
        });
    }
};
