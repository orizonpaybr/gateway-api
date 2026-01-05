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
        // Remover campo integracao_utmfy da tabela users
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'integracao_utmfy')) {
                $table->dropColumn('integracao_utmfy');
            }
        });

        // Remover campo checkout_ads_utmfy da tabela checkout_builds
        Schema::table('checkout_builds', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_builds', 'checkout_ads_utmfy')) {
                $table->dropColumn('checkout_ads_utmfy');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'integracao_utmfy')) {
                $table->string('integracao_utmfy')->nullable()->after('webhook_endpoint');
            }
        });

        Schema::table('checkout_builds', function (Blueprint $table) {
            if (!Schema::hasColumn('checkout_builds', 'checkout_ads_utmfy')) {
                $table->string('checkout_ads_utmfy')->nullable()->after('checkout_ads_tiktok');
            }
        });
    }
};
