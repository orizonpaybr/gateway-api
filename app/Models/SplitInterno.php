<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class SplitInterno extends Model
{
    protected $table = 'split_internos';
    
    protected $fillable = [
        'usuario_beneficiario_id',
        'usuario_pagador_id', 
        'porcentagem_split',
        'tipo_taxa',
        'ativo',
        'criado_por_admin_id',
        'data_inicio',
        'data_fim'
    ];

    protected $casts = [
        'porcentagem_split' => 'decimal:2',
        'ativo' => 'boolean',
        'data_inicio' => 'datetime',
        'data_fim' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Constantes para tipo de taxa
    const TAXA_DEPOSITO = 'deposito';
    const TAXA_SAQUE_PIX = 'saque_pix';

    /**
     * Relacionamento com o usuário beneficiário (quem recebe o split)
     */
    public function usuarioBeneficiario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_beneficiario_id');
    }

    /**
     * Relacionamento com o usuário pagador (quem paga o split)
     */
    public function usuarioPagador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_pagador_id');
    }

    /**
     * Relacionamento com o administrador que criou a configuração
     */
    public function criadoPorAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_admin_id');
    }

    /**
     * Relacionamento com splits executados
     */
    public function splitsExecutados(): HasMany
    {
        return $this->hasMany(SplitInternoExecutado::class, 'split_internos_id');
    }

    /**
     * Scope para configurações ativas
     */
    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true)
            ->where(function($q) {
                $q->whereNull('data_inicio')
                  ->orWhere('data_inicio', '<=', now());
            })
            ->where(function($q) {
                $q->whereNull('data_fim')
                  ->orWhere('data_fim', '>=', now());
            });
    }

    /**
     * Scope para configurações de uma taxa específica
     */
    public function scopePorTipoTaxa(Builder $query, string $tipoTaxa): Builder
    {
        return $query->where('tipo_taxa', $tipoTaxa);
    }

    /**
     * Scope para configurações de um usuério pagador específico
     */
    public function scopePorUsuarioPagador(Builder $query, int $usuarioPagadorId): Builder
    {
        return $query->where('usuario_pagador_id', $usuarioPagadorId);
    }

    /**
     * Scope para configurações de um usuário beneficiário específico
     */
    public function scopePorUsuarioBeneficiario(Builder $query, int $usuarioBeneficiarioId): Builder
    {
        return $query->where('usuario_beneficiario_id', $usuarioBeneficiarioId);
    }

    /**
     * Verifica se a configuração está válida no momento atual
     */
    public function estaValido(): bool
    {
        if (!$this->ativo) {
            return false;
        }

        $hoje = now();

        if ($this->data_inicio && $hoje < $this->data_inicio) {
            return false;
        }

        if ($this->data_fim && $hoje > $this->data_fim) {
            return false;
        }

        return true;
    }

    /**
     * Calcula o valor do split baseado na taxa cobrada
     */
    public function calcularValorSplit(float $taxaTotal): float
    {
        return ($taxaTotal * $this->porcentagem_split) / 100;
    }

    /**
     * Obtém todas as configurações válidas para um usuário pagador em um tipo de taxa específico
     */
    public static function obterConfiguracoesParaUsuario(int $usuarioPagadorId, string $tipoTaxa): array
    {
        return self::ativos()
            ->porUsuarioPagador($usuarioPagadorId) 
            ->porTipoTaxa($tipoTaxa)
            ->with(['usuarioBeneficiario'])
            ->get()
            ->toArray();
    }

    /**
     * Cria uma nova configuração de split interno
     */
    public static function criarConfiguracao(array $dados, int $adminId): self
    {
        // Validar se não existe configuração duplicada
        $existente = self::where([
            'usuario_pagador_id' => $dados['usuario_pagador_id'],
            'usuario_beneficiario_id' => $dados['usuario_beneficiario_id'],
            'tipo_taxa' => $dados['tipo_taxa']
        ])->first();

        if ($existente) {
            throw new \Exception('Já existe uma configuração de split interno entre estes usuários para este tipo de transação.');
        }

        // Validar se o beneficiário não é o próprio pagador
        if ($dados['usuario_pagador_id'] === $dados['usuario_beneficiario_id']) {
            throw new \Exception('O usuário beneficiário não pode ser o mesmo usuário pagador.');
        }

        // Validar se a porcentagem está dentro dos limites (máximo 100%)
        if ($dados['porcentagem_split'] > 100 || $dados['porcentagem_split'] <= 0) {
            throw new \Exception('A porcentagem do split deve estar entre 0% e 100%.');
        }

        return self::create([
            'usuario_beneficiario_id' => $dados['usuario_beneficiario_id'],
            'usuario_pagador_id' => $dados['usuario_pagador_id'],
            'porcentagem_split' => $dados['porcentagem_split'],
            'tipo_taxa' => $dados['tipo_taxa'],
            'ativo' => $dados['ativo'] ?? true,
            'criado_por_admin_id' => $adminId,
            'data_inicio' => $dados['data_inicio'] ?? null,
            'data_fim' => $dados['data_fim'] ?? null
        ]);
    }

    /**
     * Obtém o tipo de taxa formatado
     */
    public function getTipoTaxaFormatadoAttribute(): string
    {
        return match($this->tipo_taxa) {
            self::TAXA_DEPOSITO => 'Depósito',
            self::TAXA_SAQUE_PIX => 'Saque PIX',
            default => 'Desconhecido'
        };
    }

    /**
     * Obtém informações resumidas da configuração
     */
    public function getResumoAttribute(): string
    {
        return "Split de {$this->porcentagem_split}% das taxas de {$this->getTipoTaxaFormatadoAttribute()} de {$this->usuarioPagador->name} para {$this->usuarioBeneficiario->name}";
    }
}