<?php

namespace App\Helpers;

/**
 * Mensagens amigáveis para o webhook enviado ao cliente (Coratri → cliente final).
 * Usado quando notificamos o cliente sobre status de depósito PIX (Cash In) ou saque (Cash Out).
 *
 * Fluxo: Adquirente PIX → Coratri (webhook interno) → processamento → este payload → URL do cliente.
 */
class WebhookClientMessages
{
    /** @var array<string, string> Status enviado ao cliente => Mensagem em português (Cash Out; PAID_OUT varia por tipo) */
    private const STATUS_MESSAGES = [
        'PAID_OUT'              => 'Pagamento PIX liquidado com sucesso.',
        'CANCELLED'              => 'Saque indisponível, entre em contato com o suporte.',
        'REFUNDED'               => 'Saque PIX estornado.',
        'PARTIALLY_REFUNDED'     => 'Saque PIX estornado parcialmente.',
        'FAILED'                 => 'Saque PIX não realizado.',
        'PENDING'                => 'Saque PIX em processamento.',
        'PROCESSING'             => 'Saque PIX em processamento.',
        'INFRACTION_OPEN'        => 'Nova infração PIX registrada.',
        'INFRACTION_ACKNOWLEDGED' => 'Infração PIX em análise.',
        'INFRACTION_CLOSED'      => 'Infração PIX encerrada.',
        'INFRACTION_CANCELLED'   => 'Infração PIX cancelada.',
    ];

    /**
     * Retorna mensagem amigável para o status enviado no webhook ao cliente.
     * Para CANCELLED/FAILED em PIX_OUT, a mensagem ao cliente é fixa (sem detalhe do adquirente).
     * Para CANCELLED/FAILED em PIX_IN, se houver payload com motivo (errorCode/rejectionReason), usa PixErrorCodes.
     *
     * @param string $status Status enviado (ex.: PAID_OUT, CANCELLED, REFUNDED)
     * @param string $typeTransaction PIX_IN ou PIX_OUT (para mensagens específicas se necessário)
     * @param array<string, mixed>|null $payloadForReason Payload do webhook (para CANCELLED/FAILED com motivo)
     */
    public static function getMessageForStatus(
        string $status,
        string $typeTransaction = 'PIX_IN',
        ?array $payloadForReason = null
    ): string {
        $statusUpper = strtoupper(trim($status));

        // Saída de PIX: não repassar mensagem técnica do adquirente (ex.: "Insufficient balance")
        if ($typeTransaction === 'PIX_OUT' && in_array($statusUpper, ['CANCELLED', 'FAILED'], true)) {
            return 'Saque indisponível, entre em contato com o suporte.';
        }

        // Para cancelamento/falha, priorizar motivo do adquirente (código PIX SPI) quando disponível
        if (in_array($statusUpper, ['CANCELLED', 'FAILED']) && $payloadForReason !== null && $payloadForReason !== []) {
            $reason = PixErrorCodes::getMessageFromPayload($payloadForReason, null);
            if ($reason !== null && $reason !== '' && $reason !== 'Não informado') {
                return $reason;
            }
        }

        if ($statusUpper === 'PAID_OUT') {
            return $typeTransaction === 'PIX_IN'
                ? 'Depósito PIX recebido com sucesso.'
                : 'Saque PIX liquidado com sucesso.';
        }
        if ($statusUpper === 'CANCELLED' && $typeTransaction === 'PIX_IN') {
            return 'Depósito PIX cancelado ou expirado.';
        }
        if ($statusUpper === 'FAILED' && $typeTransaction === 'PIX_IN') {
            return 'Depósito PIX não realizado.';
        }
        if ($statusUpper === 'REFUNDED' && $typeTransaction === 'PIX_IN') {
            return 'Depósito PIX estornado.';
        }
        if ($statusUpper === 'PARTIALLY_REFUNDED' && $typeTransaction === 'PIX_IN') {
            return 'Depósito PIX estornado parcialmente.';
        }
        if (isset(self::STATUS_MESSAGES[$statusUpper])) {
            return self::STATUS_MESSAGES[$statusUpper];
        }

        if ($typeTransaction === 'PIX_OUT') {
            return 'Status do saque PIX: ' . $statusUpper . '.';
        }
        return 'Status do depósito PIX: ' . $statusUpper . '.';
    }
}
