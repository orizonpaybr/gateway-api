<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adiciona campo token_lookup para busca eficiente quando tokens estão criptografados.
     * O token_lookup é um hash SHA256 do token original, permitindo busca rápida
     * sem expor o token real no banco de dados.
     */
    public function up(): void
    {
        Schema::table('users_key', function (Blueprint $table) {
            // Hash do token para busca eficiente (não reversível)
            $table->string('token_lookup', 64)->nullable()->after('token')->index();
        });
        
        // Preencher token_lookup para registros existentes
        // Isso assume que os tokens ainda não estão criptografados
        // Após rodar esta migration, execute: php artisan users:encrypt-keys
        DB::statement("UPDATE users_key SET token_lookup = SHA2(token, 256) WHERE token_lookup IS NULL AND token IS NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_key', function (Blueprint $table) {
            $table->dropColumn('token_lookup');
        });
    }
};
