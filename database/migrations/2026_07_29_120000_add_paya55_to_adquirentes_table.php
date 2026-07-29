<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')->updateOrInsert(
            ['referencia' => 'paya55'],
            [
                'adquirente' => 'Paya55',
                'provider' => 'paya55',
                'status' => 0,
                'is_default' => 0,
                'url' => 'https://api.paya55.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'paya55')->delete();
    }
};
