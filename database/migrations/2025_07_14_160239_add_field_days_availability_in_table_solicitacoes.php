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
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->longText('banking_billet')->nullable()->default(NULL)->change();
            $table->integer('days_availability')->nullable()->default(NULL);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            //
        });
    }
};
