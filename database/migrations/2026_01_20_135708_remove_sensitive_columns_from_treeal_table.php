<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove colunas sensíveis que foram movidas para o .env
     */
    public function up(): void
    {
        Schema::table('treeal', function (Blueprint $table) {
            $table->dropColumn([
                'certificate_path',
                'certificate_password',
                'client_id',
                'client_secret',
                'qrcodes_client_id',
                'qrcodes_client_secret',
                'pix_key_secondary',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     * 
     * Recria as colunas removidas (caso precise reverter)
     */
    public function down(): void
    {
        Schema::table('treeal', function (Blueprint $table) {
            $table->string('certificate_path')->nullable()->after('accounts_api_url')->comment('Caminho do certificado .PFX');
            $table->string('certificate_password')->nullable()->after('certificate_path')->comment('Senha do certificado');
            $table->string('client_id')->nullable()->after('certificate_password')->comment('Client ID para OAuth2');
            $table->string('client_secret')->nullable()->after('client_id')->comment('Client Secret para OAuth2');
            $table->string('qrcodes_client_id')->nullable()->after('client_secret')->comment('Client ID para QR Codes API OAuth2');
            $table->string('qrcodes_client_secret')->nullable()->after('qrcodes_client_id')->comment('Client Secret para QR Codes API OAuth2');
            $table->string('pix_key_secondary')->nullable()->after('qrcodes_client_secret')->comment('Chave PIX secundária para testes');
        });
    }
};
