<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->text('webhook_request_body')->nullable()->after('webhook_attempts');
        });

        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->text('webhook_request_body')->nullable()->after('webhook_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->dropColumn('webhook_request_body');
        });

        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->dropColumn('webhook_request_body');
        });
    }
};
