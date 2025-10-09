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
        Schema::table('primepay7', function (Blueprint $table) {
            $table->string('private_key')->nullable()->after('client_secret');
            $table->string('public_key')->nullable()->after('private_key');
            $table->string('withdrawal_key')->nullable()->after('public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('primepay7', function (Blueprint $table) {
            $table->dropColumn(['private_key', 'public_key', 'withdrawal_key']);
        });
    }
};
