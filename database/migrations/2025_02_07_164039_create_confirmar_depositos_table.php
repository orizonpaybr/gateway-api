<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Migration para confirmar_deposito
return new class extends Migration {
    public function up(): void
    {
        Schema::create('confirmar_deposito', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('externalreference');
            $table->string('valor');
            $table->string('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmar_deposito');
    }
};