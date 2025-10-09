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
        Schema::table('xdpag', function (Blueprint $table) {
            // Adicionar campos básicos necessários
            $table->string('url')->nullable();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('xdpag', function (Blueprint $table) {
            // Remover campos adicionados
            $table->dropColumn(['url', 'client_id', 'client_secret']);
        });
    }
};