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
        Schema::table('xdpag', function (Blueprint $table) {
            // Verificar se os campos existem antes de tentar removê-los
            if (Schema::hasColumn('xdpag', 'client_id')) {
                $table->dropColumn('client_id');
            }
            if (Schema::hasColumn('xdpag', 'client_secret')) {
                $table->dropColumn('client_secret');
            }
            if (Schema::hasColumn('xdpag', 'url')) {
                $table->dropColumn('url');
            }
            
            // Adicionar campo status se não existir
            if (!Schema::hasColumn('xdpag', 'status')) {
                $table->boolean('status')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('xdpag', function (Blueprint $table) {
            // Restaurar campos removidos
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('url')->nullable();
            
            // Remover campo status
            $table->dropColumn('status');
        });
    }
};