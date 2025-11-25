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
            $columns = [
                'gateway_name',
                'gateway_color',
                'gateway_logo',
                'gateway_favicon',
                'contato',
                'cnpj',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('app', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            if (!Schema::hasColumn('app', 'gateway_name')) {
                $table->string('gateway_name')->nullable()->comment('Nome do gateway');
            }

            if (!Schema::hasColumn('app', 'gateway_color')) {
                $table->string('gateway_color')->nullable()->default('#ff0000')->comment('Cor padrão do gateway');
            }

            if (!Schema::hasColumn('app', 'gateway_logo')) {
                $table->string('gateway_logo')->nullable()->comment('Logo do gateway');
            }

            if (!Schema::hasColumn('app', 'gateway_favicon')) {
                $table->string('gateway_favicon')->nullable()->comment('Ícone/favicon do gateway');
            }

            if (!Schema::hasColumn('app', 'contato')) {
                $table->string('contato')->nullable()->comment('Contato do gerente');
            }

            if (!Schema::hasColumn('app', 'cnpj')) {
                $table->string('cnpj')->nullable()->comment('CNPJ do gateway');
            }
        });
    }
};
