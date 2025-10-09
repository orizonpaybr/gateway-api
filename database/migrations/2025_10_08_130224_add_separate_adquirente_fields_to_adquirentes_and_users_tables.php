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
        // Adicionar novos campos na tabela adquirentes
        Schema::table('adquirentes', function (Blueprint $table) {
            // Renomear is_default para is_default_pix (para PIX apenas)
            // Adicionar novo campo is_default_card_billet para cartão+boleto
            $table->boolean('is_default_card_billet')->default(0)->after('is_default');
        });

        // Adicionar novos campos na tabela users
        Schema::table('users', function (Blueprint $table) {
            // Adicionar campo para adquirente específica de Cartão+Boleto
            $table->string('preferred_adquirente_card_billet')->nullable()->after('preferred_adquirente');
            $table->boolean('adquirente_card_billet_override')->default(0)->after('adquirente_override');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adquirentes', function (Blueprint $table) {
            $table->dropColumn('is_default_card_billet');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_adquirente_card_billet',
                'adquirente_card_billet_override'
            ]);
        });
    }
};
