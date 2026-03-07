<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->string('payer_name', 255)->nullable()->after('client_telefone');
            $table->string('payer_document', 20)->nullable()->after('payer_name');
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->dropColumn(['payer_name', 'payer_document']);
        });
    }
};
