<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')->updateOrInsert(
            ['referencia' => 'treeal'],
            [
                'adquirente' => 'TREEAL',
                'status' => 0,
                'is_default' => 0,
                'url' => 'https://api.qrcodes-h.sulcredi.coop.br',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'treeal')->delete();
    }
};
