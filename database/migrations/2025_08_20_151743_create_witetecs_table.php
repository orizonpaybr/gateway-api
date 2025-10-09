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
        Schema::create('witetec', function (Blueprint $table) {
            $table->id();
            $table->string('url')->default('https://api.witetec.net');
            $table->string('api_token')->nullable()->default(NULL);
            $table->decimal('tx_billet_fixed',10,2)->default(5);
            $table->decimal('tx_billet_percent',10,2)->default(5);
            $table->decimal('tx_card_fixed',10,2)->default(5);
            $table->decimal('tx_card_percent',10,2)->default(5);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('witetec');
    }
};
