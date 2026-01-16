<?php

namespace App\DTO\PagarMeDTO;

use Illuminate\Http\Request;

/**
 * DTO para depósitos via cartão de crédito na Pagar.me
 */
class CardDepositDTO
{
    public function __construct(
        // Dados do depósito
        public float $amount,
        public string $externalId,
        public ?string $description = null,
        
        // Dados do cliente
        public string $customerName,
        public string $customerEmail,
        public string $customerDocument,
        public ?string $customerPhone = null,
        
        // Dados do cartão (uma das opções)
        public ?string $cardId = null,        // Cartão salvo
        public ?string $cardToken = null,     // Token do Tokenizecard JS
        public ?CardDataDTO $card = null,     // Dados completos (não recomendado)
        
        // Opções de pagamento
        public int $installments = 1,
        public ?string $statementDescriptor = null,
        
        // 3D Secure
        public bool $use3ds = true,
        public ?ThreeDSecureDTO $threeDSecure = null,
        
        // Callback
        public ?string $callbackUrl = null,
        
        // Metadados
        public array $metadata = [],
    ) {}

    /**
     * Cria DTO a partir de uma Request
     */
    public static function fromRequest(Request $request): self
    {
        $cardData = null;
        if ($request->has('card') && is_array($request->card)) {
            $cardData = CardDataDTO::fromArray($request->card);
        }

        $threeDSecure = null;
        if ($request->has('threed_secure') && is_array($request->threed_secure)) {
            $threeDSecure = ThreeDSecureDTO::fromArray($request->threed_secure);
        }

        return new self(
            amount: (float) $request->input('amount'),
            externalId: $request->input('external_id', uniqid('CARD_')),
            description: $request->input('description'),
            customerName: $request->input('debtor_name', $request->input('customer_name', 'Cliente')),
            customerEmail: $request->input('email', $request->input('customer_email', 'cliente@email.com')),
            customerDocument: $request->input('debtor_document', $request->input('customer_document', '')),
            customerPhone: $request->input('phone', $request->input('customer_phone')),
            cardId: $request->input('card_id'),
            cardToken: $request->input('card_token'),
            card: $cardData,
            installments: (int) $request->input('installments', 1),
            statementDescriptor: $request->input('statement_descriptor'),
            use3ds: (bool) $request->input('use_3ds', true),
            threeDSecure: $threeDSecure,
            callbackUrl: $request->input('callbackUrl', $request->input('callback_url')),
            metadata: $request->input('metadata', []),
        );
    }

    /**
     * Converte para array compatível com PagarMeService
     */
    public function toServiceArray(): array
    {
        $data = [
            'amount' => $this->amount,
            'external_id' => $this->externalId,
            'description' => $this->description ?? 'Depósito via cartão de crédito',
            'customer_name' => $this->customerName,
            'customer_email' => $this->customerEmail,
            'customer_document' => $this->customerDocument,
            'customer_phone' => $this->customerPhone,
            'installments' => $this->installments,
            'statement_descriptor' => $this->statementDescriptor,
            'use_3ds' => $this->use3ds,
            'callback_url' => $this->callbackUrl,
            'metadata' => $this->metadata,
        ];

        // Adicionar dados do cartão conforme disponibilidade
        if ($this->cardId) {
            $data['card_id'] = $this->cardId;
        } elseif ($this->cardToken) {
            $data['card_token'] = $this->cardToken;
        } elseif ($this->card) {
            $data['card'] = $this->card->toArray();
        }

        // Adicionar dados 3DS se fornecidos externamente
        if ($this->threeDSecure) {
            $data['threed_secure'] = $this->threeDSecure->toArray();
        }

        return $data;
    }

    /**
     * Valida se o DTO tem dados mínimos necessários
     */
    public function isValid(): bool
    {
        // Deve ter valor
        if ($this->amount <= 0) {
            return false;
        }

        // Deve ter alguma forma de pagamento com cartão
        if (!$this->cardId && !$this->cardToken && !$this->card) {
            return false;
        }

        // Deve ter documento do cliente
        if (empty($this->customerDocument)) {
            return false;
        }

        return true;
    }

    /**
     * Retorna erros de validação
     */
    public function getValidationErrors(): array
    {
        $errors = [];

        if ($this->amount <= 0) {
            $errors['amount'] = 'O valor deve ser maior que zero';
        }

        if (!$this->cardId && !$this->cardToken && !$this->card) {
            $errors['card'] = 'É necessário informar card_id, card_token ou dados do cartão';
        }

        if (empty($this->customerDocument)) {
            $errors['customer_document'] = 'O documento do cliente é obrigatório';
        }

        if ($this->installments < 1 || $this->installments > 12) {
            $errors['installments'] = 'O número de parcelas deve ser entre 1 e 12';
        }

        return $errors;
    }
}
