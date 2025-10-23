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
            $table->enum('method', ['pix','billet','card'])->default('pix');
            $table->string('expire_at')->nullable()->default(NULL);
            $table->string('billet_download')->nullable()->default(NULL);
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
