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
        Schema::create('efi', function (Blueprint $table) {
            $table->id();
            $table->string('access_token')->nullable()->default(NULL);
            $table->string('client_id')->nullable()->default(NULL);
            $table->string('client_secret')->nullable()->default(NULL);
            $table->string('gateway_id')->nullable()->default(NULL);
            $table->string('cert')->nullable()->default(NULL);
            $table->decimal('taxa_pix_cash_in', 10, 2)->default(5.00);
            $table->decimal('taxa_pix_cash_out', 10, 2)->default(5.00);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('efi');
    }
};
