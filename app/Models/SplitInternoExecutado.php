<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class SplitInternoExecutado extends Model
{
    protected $table = 'split_internos_executados';
    
    protected $fillable = [
        'split_internos_id',
        'solicitacao_id',
        'usuario_pagador_id',
        'usuario_beneficiario_id',
        'valor_taxa_original',
        'valor_split',
        'porcentagem_aplicada',
        'status',
        'processado_em',
        'observacoes'
    ];

    protected $casts = [
        'valor_taxa_original' => 'decimal:2',
        'valor_split' => 'decimal:2',
        'porcentagem_aplicada' => 'decimal:2',
        'processado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Status possíveis
    const STATUS_PENDENTE = 'pendente';
    const STATUS_PROCESSADO = 'processado';
    const STATUS_FALHADO = 'falhado';

    /**
     * Relacionamento com a configuração de split interno
     */
    public function splitInterno(): BelongsTo
    {
        return $this->belongsTo(SplitInterno::class, 'split_internos_id');
    }

    /**
     * Relacionamento com a solicitação de transação
     */
    public function solicitacao(): BelongsTo
    {
        return $this->belongsTo(Solicitacoes::class, 'solicitacao_id');
    }

    /**
     * Relacionamento com o usuário pagador
     */
    public function usuarioPagador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_pagador_id');
    }

    /**
     * Relacionamento com o usuário beneficiário
     */
    public function usuarioBeneficiario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_beneficiario_id');
    }

    /**
     * Scope para splits pendentes
     */
    public function scopePendentes(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDENTE);
    }

    /**
     * Scope para splits processados
     */
    public function scopeProcessados(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSADO);
    }

    /**
     * Scope para splits falhados
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FALHADO);
    }

    /**
     * Scope por usuário beneficiário
     */
    public function scopePorBeneficiario(Builder $query, int $usuarioBeneficiarioId): Builder
    {
        return $query->where('usuario_beneficiario_id', $usuarioBeneficiarioId);
    }

    /**
     * Scope por usuário pagador
     */
    public function scopePorPagador(Builder $query, int $usuarioPagadorId): Builder
    {
        return $query->where('usuario_pagador_id', $usuarioPagadorId);
    }

    /**
     * Scope por período
     */
    public function scopePorPeriodo(Builder $query, Carbon $inicio, Carbon $fim): Builder
    {
        return $query->whereBetween('created_at', [$inicio, $fim]);
    }

    /**
     * Verifica se o split está pendente
     */
    public function estaPendente(): bool
    {
        return $this->status === self::STATUS_PENDENTE;
    }

    /**
     * Verifica se o split foi processado com sucesso
     */
    public function foiProcessado(): bool
    {
        return $this->status === self::STATUS_PROCESSADO;
    }

    /**
     * Verifica se o split falhou
     */
    public function falhou(): bool
    {
        return $this->status === self::STATUS_FALHADO;
    }

    /**
     * Marca o split como processado
     */
    public function marcarComoProcessado(string $observacoes = null): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSADO,
            'processado_em' => now(),
            'observacoes' => $observacoes
        ]);
    }

    /**
     * Marca o split como falhado
     */
    public function marcarComoFalhado(string $motivo = null): void
    {
        $this->update([
            'status' => self::STATUS_FALHADO,
            'processado_em' => now(),
            'observacoes' => $motivo
        ]);
    }

    /**
     * Obtém o status formatado
     */
    public function getStatusFormatadoAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDENTE => 'Pendente',
            self::STATUS_PROCESSADO => 'Processado',
            self::STATUS_FALHADO => 'Falhou',
            default => 'Desconhecido'
        };
    }

    /**
     * Obtém estatísticas do split executado
     */
    public function getEstatisticasAttribute(): array
    {
        return [
            'valor_split_formatado' => 'R$ ' . number_format($this->valor_split, 2, ',', '.'),
            'taxa_original_formatada' => 'R$ ' . number_format($this->valor_taxa_original, 2, ',', '.'),
            'porcentagem_real' => number_format($this->porcentagem_aplicada, 2) . '%',
            'status_color' => match($this->status) {
                self::STATUS_PENDENTE => 'text-warning',
                self::STATUS_PROCESSADO => 'text-success',
                self::STATUS_FALHADO => 'text-danger',
                default => 'text-secondary'
            }
        ];
    }

    /**
     * Cria um novo registro de split executado
     */
    public static function criarSplit(int $splitInternosId, int $solicitacaoId, array $dados): self
    {
        return self::create([
            'split_internos_id' => $splitInternosId,
            'solicitacao_id' => $solicitacaoId ?? null,
            'usuario_pagador_id' => $dados['usuario_pagador_id'],
            'usuario_beneficiario_id' => $dados['usuario_beneficiario_id'],
            'valor_taxa_original' => $dados['valor_taxa_original'],
            'valor_split' => $dados['valor_split'],
            'porcentagem_aplicada' => $dados['porcentagem_aplicada'],
            'status' => self::STATUS_PENDENTE,
            'observacoes' => $dados['observacoes'] ?? null
        ]);
    }

    /**
     * Remove erro de digitação na tabela
     */
    public function getUsarioBeneficiarioIdAttribute()
    {
        return $this->attributes['usuario_beneficiario_id'] ?? $this->attributes['usuairo_beneficiario_id'] ?? null;
    }
}