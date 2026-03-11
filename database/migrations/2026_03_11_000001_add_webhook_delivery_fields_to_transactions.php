<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->string('webhook_status', 20)->nullable()->after('callback');
            $table->timestamp('webhook_sent_at')->nullable()->after('webhook_status');
            $table->unsignedSmallInteger('webhook_http_status')->nullable()->after('webhook_sent_at');
            $table->string('webhook_error', 500)->nullable()->after('webhook_http_status');
            $table->unsignedTinyInteger('webhook_attempts')->default(0)->after('webhook_error');
        });

        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->string('webhook_status', 20)->nullable()->after('callback');
            $table->timestamp('webhook_sent_at')->nullable()->after('webhook_status');
            $table->unsignedSmallInteger('webhook_http_status')->nullable()->after('webhook_sent_at');
            $table->string('webhook_error', 500)->nullable()->after('webhook_http_status');
            $table->unsignedTinyInteger('webhook_attempts')->default(0)->after('webhook_error');
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->dropColumn(['webhook_status', 'webhook_sent_at', 'webhook_http_status', 'webhook_error', 'webhook_attempts']);
        });

        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->dropColumn(['webhook_status', 'webhook_sent_at', 'webhook_http_status', 'webhook_error', 'webhook_attempts']);
        });
    }
};
