<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('login_lockout_tier')->default(0)->after('locked_until');
            $table->boolean('login_lockout_final_chance')->default(false)->after('login_lockout_tier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login_lockout_tier', 'login_lockout_final_chance']);
        });
    }
};
