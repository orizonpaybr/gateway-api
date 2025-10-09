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
           /*  $table->string('code_ref')->nullable(); // Permite nulo
            $table->string('indicador_ref')->nullable(); // Pode ser nulo

            // Garante que code_ref seja único caso seja necessário
            $table->unique('code_ref');

            // Define indicador_ref como chave estrangeira de code_ref
            $table->foreign('indicador_ref')->references('code_ref')->on('users')->nullOnDelete(); */
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['indicador_ref']);
            $table->dropUnique(['code_ref']);
            $table->dropColumn(['code_ref', 'indicador_ref']);
        });
    }
};
