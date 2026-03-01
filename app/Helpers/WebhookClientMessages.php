<?php

namespace App\Helpers;

/**
 * Mensagens amigáveis para o webhook enviado ao cliente (Orizon → cliente final).
 * Usado quando notificamos o cliente sobre status de depósito PIX (Cash In) ou saque (Cash Out).
 *
 * Fluxo: Treeal → Orizon (webhook interno) → processamento → este payload → URL do cliente.
 */
class WebhookClientMessages
{
    /** @var array<string, string> Status enviado ao cliente => Mensagem em português (Cash Out; PAID_OUT varia por tipo) */
    private const STATUS_MESSAGES = [
        'PAID_OUT'              => 'Pagamento PIX liquidado com sucesso.',
        'CANCELLED'              => 'Saque PIX cancelado.',
        'REFUNDED'               => 'Saque PIX estornado.',
        'PARTIALLY_REFUNDED'     => 'Saque PIX estornado parcialmente.',
        'FAILED'                 => 'Saque PIX não realizado.',
        'PENDING'                => 'Saque PIX em processamento.',
        'PROCESSING'             => 'Saque PIX em processamento.',
    ];

    /**
     * Retorna mensagem amigável para o status enviado no webhook ao cliente.
     * Para CANCELLED, se houver payload da Treeal com motivo (errorCode/rejectionReason), usa PixErrorCodes.
     *
     * @param string $status Status enviado (ex.: PAID_OUT, CANCELLED, REFUNDED)
     * @param string $typeTransaction PIX_IN ou PIX_OUT (para mensagens específicas se necessário)
     * @param array<string, mixed>|null $payloadForReason Payload do webhook Treeal (para CANCELLED/FAILED com motivo)
     */
    public static function getMessageForStatus(
        string $status,
        string $typeTransaction = 'PIX_IN',
        ?array $payloadForReason = null
    ): string {
        $statusUpper = strtoupper(trim($status));

        // Para cancelamento/falha, priorizar motivo da Treeal (código PIX SPI) quando disponível
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
        if (isset(self::STATUS_MESSAGES[$statusUpper])) {
            return self::STATUS_MESSAGES[$statusUpper];
        }

        if ($typeTransaction === 'PIX_OUT') {
            return 'Status do saque PIX: ' . $statusUpper . '.';
        }
        return 'Status do depósito PIX: ' . $statusUpper . '.';
    }
}
