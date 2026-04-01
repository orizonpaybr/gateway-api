<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adquirentes')) {
            DB::table('adquirentes')
                ->whereIn('referencia', ['treeal', 'magenpay', 'heartpay', 'hyperx'])
                ->delete();

            DB::table('adquirentes')->update(['is_default' => 0]);

            $fallback = DB::table('adquirentes')
                ->where('status', 1)
                ->orderBy('id')
                ->first();

            if ($fallback !== null) {
                DB::table('adquirentes')
                    ->where('id', $fallback->id)
                    ->update([
                        'is_default' => 1,
                        'updated_at' => now(),
                    ]);
            }
        }

        Schema::dropIfExists('treeal');
    }

    public function down(): void
    {
        // noop
    }
};
