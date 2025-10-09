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
        Schema::create('niveis', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->nullable()->default(NULL);
            $table->string('cor')->nullable()->default(NULL);
            $table->string('icone')->nullable()->default(NULL);
            $table->decimal('minimo', 10, 2)->default(0.00);
            $table->decimal('maximo', 10, 2)->default(0.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('niveis');
    }
};
