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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'twofa_secret')) {
                $table->string('twofa_secret')->nullable();
            }
            if (!Schema::hasColumn('users', 'twofa_enabled')) {
                $table->boolean('twofa_enabled')->default(false);
            }
            if (!Schema::hasColumn('users', 'twofa_enabled_at')) {
                $table->timestamp('twofa_enabled_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['twofa_secret', 'twofa_enabled', 'twofa_enabled_at']);
        });
    }
};
