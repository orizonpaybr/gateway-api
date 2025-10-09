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
        Schema::table('app', function (Blueprint $table) {
            $table->json('global_ips')->nullable()->after('taxa_pix_valor_real_cash_in_padrao')
                ->comment('IPs globais autorizados para todos os usuários (interface web)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            $table->dropColumn('global_ips');
        });
    }
};