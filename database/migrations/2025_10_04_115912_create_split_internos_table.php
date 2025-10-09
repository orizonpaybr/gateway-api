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
        Schema::create('split_internos', function (Blueprint $table) {
            $table->id();
            
            // Usuário que irá receber o split interno (destinatário)
            $table->unsignedBigInteger('usuario_beneficiario_id')->index();
            
            // Usuário que terá suas taxas compartilhadas (pagador)
            $table->unsignedBigInteger('usuario_pagador_id')->index();
            
            // Porcentagem da taxa que será enviada como split interno
            $table->decimal('porcentagem_split', 5, 2);
            
            // Tipo de taxa que se aplica o split
            $table->enum('tipo_taxa', ['deposito', 'saque_pix'])->default('deposito');
            
            // Status da configuração
            $table->boolean('ativo')->default(true);
            
            // Configuração criada por
            $table->unsignedBigInteger('criado_por_admin_id')->index();
            
            // Datas
            $table->timestamp('data_inicio')->nullable();
            $table->timestamp('data_fim')->nullable();
            
            // Campos de auditoria
            $table->timestamps();
            
            // Índices
            $table->index(['usuario_pagador_id', 'tipo_taxa', 'ativo']);
            $table->index(['usuario_beneficiario_id', 'ativo']);
            
            // Foreign keys
            $table->foreign('usuario_beneficiario_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('usuario_pagador_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('criado_por_admin_id')->references('id')->on('users')->onDelete('cascade');
            
            // Constraint única para evitar configurações duplicadas
            $table->unique(['usuario_pagador_id', 'usuario_beneficiario_id', 'tipo_taxa'], 'unique_split_config');
        });
        
        // Criar tabela para registrar os splits internos executados
        Schema::create('split_internos_executados', function (Blueprint $table) {
            $table->id();
            
            // Referência para a configuração do split interno
            $table->unsignedBigInteger('split_internos_id');
            
            // Transação que gerou o split
            $table->unsignedBigInteger('solicitacao_id')->nullable();
            
            // Usuários envolvidos
            $table->unsignedBigInteger('usuario_pagador_id');
            $table->unsignedBigInteger('usuario_beneficiario_id');
            
            // Valores e detalhes
            $table->decimal('valor_taxa_original', 12, 2);
            $table->decimal('valor_split', 12, 2);
            $table->decimal('porcentagem_aplicada', 5, 2);
            
            // Status do split
            $table->enum('status', ['pendente', 'processado', 'falhado'])->default('pendente');
            
            // Data de processamento
            $table->timestamp('processado_em')->nullable();
            
            // Observações
            $table->text('observacoes')->nullable();
            
            // Campos de auditoria
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('split_internos_id')->references('id')->on('split_internos')->onDelete('cascade');
            $table->foreign('solicitacao_id')->references('id')->on('solicitacoes')->onDelete('set null');
            $table->foreign('usuario_pagador_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('usuario_beneficiario_id')->references('id')->on('users')->onDelete('cascade');
            
            // Índices para consultas (corrigindo erro de digitação usuairo -> usuario)
            $table->index(['usuario_beneficiario_id', 'status', 'created_at'], 'idx_executados_benef_status_data');
            $table->index(['usuario_pagador_id', 'status', 'created_at'], 'idx_executados_pag_status_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('split_internos_executados');
        Schema::dropIfExists('split_internos');
    }
};