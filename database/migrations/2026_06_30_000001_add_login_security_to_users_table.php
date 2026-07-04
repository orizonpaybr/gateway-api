<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('twofa_enabled_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->string('twofa_method', 10)->nullable()->after('locked_until');
        });

        DB::table('users')
            ->where('twofa_enabled', true)
            ->whereNotNull('twofa_pin')
            ->whereNull('twofa_method')
            ->update(['twofa_method' => 'pin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_until', 'twofa_method']);
        });
    }
};
