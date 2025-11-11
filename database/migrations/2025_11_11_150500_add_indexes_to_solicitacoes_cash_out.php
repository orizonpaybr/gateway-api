<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            // Índices para melhorar filtros e ordenações frequentes
            $table->index(['descricao_transacao', 'status'], 'sco_desc_status_idx');
            $table->index(['date'], 'sco_date_idx');
            $table->index(['executor_ordem'], 'sco_executor_idx');
            $table->index(['created_at'], 'sco_created_idx');
            $table->index(['updated_at'], 'sco_updated_idx');
            $table->index(['externalreference'], 'sco_extref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            $table->dropIndex('sco_desc_status_idx');
            $table->dropIndex('sco_date_idx');
            $table->dropIndex('sco_executor_idx');
            $table->dropIndex('sco_created_idx');
            $table->dropIndex('sco_updated_idx');
            $table->dropIndex('sco_extref_idx');
        });
    }
};


