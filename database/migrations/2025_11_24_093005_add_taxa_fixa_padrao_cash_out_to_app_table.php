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
        Schema::table('app', function (Blueprint $table) {
            if (!Schema::hasColumn('app', 'taxa_fixa_padrao_cash_out')) {
                $table->decimal('taxa_fixa_padrao_cash_out', 10, 2)
                    ->default(0.00)
                    ->after('taxa_fixa_padrao')
                    ->comment('Taxa fixa aplicada nas operações de saque padrão');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            if (Schema::hasColumn('app', 'taxa_fixa_padrao_cash_out')) {
                $table->dropColumn('taxa_fixa_padrao_cash_out');
            }
        });
    }
};
