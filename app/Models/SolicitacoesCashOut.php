<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class SolicitacoesCashOut extends Model
{
    use HasFactory;
    
    protected $table = "solicitacoes_cash_out";

    protected $fillable = [
        "user_id",
        "externalreference",
        "amount",
        "beneficiaryname",
        "beneficiarydocument",
        "pix",
        "pixkey",
        "date",
        "status",
        "type",
        "idTransaction",
        "taxa_cash_out",
        "debito_saldo_afiliado",
        "debito_saldo_principal",
        "valor_total_descontado",
        "cash_out_liquido",
        "end_to_end",
        "descricao_transacao",
        "executor_ordem",
        "adquirente_ref",
      	"descricao_externa",
        "blockchainNetwork",
        "cryptocurrency",
        "callback",
        "primepay7_id",
        "webhook_status",
        "webhook_sent_at",
        "webhook_http_status",
        "webhook_error",
        "webhook_attempts",
        "webhook_request_body"
    ];

    public $casts = [
        "blockchainNetwork" => "array",
        "cryptocurrency" => "array",
        "amount" => "decimal:2",
        "taxa_cash_out" => "decimal:2",
        "valor_total_descontado" => "decimal:4",
        "debito_saldo_afiliado" => "decimal:4",
        "debito_saldo_principal" => "decimal:4",
        "cash_out_liquido" => "decimal:2",
    ];

    /**
     * Relacionamento com usuário
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Scope: Apenas saques pendentes
     */
    public function scopePending(Builder $query)
    {
        return $query->where('status', 'PENDING');
    }

    /**
     * Scope: Apenas saques concluídos
     */
    public function scopeCompleted(Builder $query)
    {
        return $query->where('status', 'COMPLETED');
    }

    /**
     * Scope: Apenas saques cancelados
     */
    public function scopeCancelled(Builder $query)
    {
        return $query->where('status', 'CANCELLED');
    }

    /**
     * Scope: Apenas saques via web
     */
    public function scopeWebOnly(Builder $query)
    {
        return $query->where('descricao_transacao', 'WEB');
    }

    /**
     * Scope: Apenas saques manuais
     */
    public function scopeManual(Builder $query)
    {
        return $query->whereNull('executor_ordem');
    }

    /**
     * Scope: Apenas saques automáticos
     */
    public function scopeAutomatic(Builder $query)
    {
        return $query->whereNotNull('executor_ordem');
    }

    /**
     * Scope: Filtrar por período
     */
    public function scopePeriod(Builder $query, $dataInicio, $dataFim = null)
    {
        if ($dataInicio) {
            $query->whereDate('date', '>=', $dataInicio);
        }
        if ($dataFim) {
            $query->whereDate('date', '<=', $dataFim);
        }
        return $query;
    }

    /**
     * Verificar se é um saque manual
     */
    public function isManual(): bool
    {
        return empty($this->executor_ordem);
    }

    /**
     * Verificar se é um saque automático
     */
    public function isAutomatic(): bool
    {
        return !$this->isManual();
    }

    /**
     * Estados em que um Pix Out está reservado/em voo para a adquirente.
     * Um novo saque do mesmo cliente enquanto há um destes é duplicidade.
     */
    public const IN_FLIGHT_STATUSES = ['PENDING', 'PROCESSING'];

    /**
     * Janela (segundos) em que um saque em voo bloqueia um novo do mesmo cliente.
     *
     * Curta de propósito: o risco real (duplo-clique / requisições concorrentes) é de
     * SEGUNDOS, e a Flux liquida em 3-5s no caso normal (p90=5s). Mas ~4% arrastam até
     * ~10min esperando o webhook de confirmação — e nesses casos o saque fica PROCESSING
     * (já aceito pela adquirente, e2e emitido, saldo debitado): um novo saque aí não é
     * duplicidade, é um pagamento legítimo separado. Por isso a janela cobre só o risco
     * real (90s) em vez de prender o cliente pela liquidação lenta da adquirente.
     * Ceiling: linha travada além disso é problema do reaper, nunca prende o cliente.
     */
    public const IN_FLIGHT_GUARD_SECONDS = 90;

    /**
     * Há um saque em voo (recente) para este cliente? Serializa saques: um por vez.
     * Deve ser chamado DENTRO da transação que segura o lock do usuário, senão a
     * checagem tem race (dois pedidos veem "nenhum em voo" e ambos disparam).
     */
    public static function userHasInFlightWithdrawal(User $user): bool
    {
        $identifiers = array_values(array_filter([
            $user->user_id,
            $user->username,
        ], fn ($v) => $v !== null && $v !== ''));

        if ($identifiers === []) {
            return false;
        }

        return static::query()
            ->whereIn('user_id', $identifiers)
            ->whereIn('status', self::IN_FLIGHT_STATUSES)
            ->where('created_at', '>', now()->subSeconds(self::IN_FLIGHT_GUARD_SECONDS))
            ->exists();
    }

    /**
     * Verificar se está pendente
     */
    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    /**
     * Verificar se foi aprovado
     */
    public function isApproved(): bool
    {
        return in_array($this->status, ['COMPLETED', 'PAID_OUT']);
    }

    /**
     * Verificar se foi rejeitado
     */
    public function isRejected(): bool
    {
        return $this->status === 'CANCELLED';
    }

    /**
     * Label do status para exibição.
     * PROCESSING = PIX já enviado, exibir como concluído.
     */
    public function getStatusLabel(): string
    {
        $labels = [
            'PENDING' => 'Pendente',
            'COMPLETED' => 'Concluído',
            'PAID_OUT' => 'Pago',
            'CANCELLED' => 'Cancelado',
            'FAILED' => 'Falhou',
            'PROCESSING' => 'Concluído', // PIX enviado
        ];

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * Obter tipo de processamento
     */
    public function getTipoProcessamento(): string
    {
        return $this->isManual() ? 'Manual' : 'Automático';
    }
}
