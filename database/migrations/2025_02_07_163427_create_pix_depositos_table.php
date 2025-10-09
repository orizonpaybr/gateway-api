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
        Schema::create('pix_deposito', function (Blueprint $table) {
            $table->id();
            $table->decimal('value', 10, 2);
            $table->string('email')->nullable();
            $table->string('code')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('data')->useCurrent();
            $table->string('user_id')->nullable();

            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pix_deposito');
    }
};