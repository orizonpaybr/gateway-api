<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')
            ->where('referencia', 'simpay')
            ->update([
                'status' => 1,
                'is_default' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('adquirentes')
            ->where('referencia', 'simpay')
            ->update([
                'status' => 0,
                'is_default' => 0,
                'updated_at' => now(),
            ]);
    }
};
