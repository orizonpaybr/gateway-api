<?php

namespace App\DTO\PagarMeDTO;

/**
 * DTO para dados do cartão de crédito
 * 
 * ATENÇÃO: Usar apenas para testes ou quando não for possível usar tokenização.
 * Em produção, sempre preferir card_token ou card_id para compliance PCI DSS.
 */
class CardDataDTO
{
    public function __construct(
        public string $number,
        public string $holderName,
        public int $expMonth,
        public int $expYear,
        public string $cvv,
        public ?BillingAddressDTO $billingAddress = null,
    ) {}

    /**
     * Cria DTO a partir de array
     */
    public static function fromArray(array $data): self
    {
        $billingAddress = null;
        if (isset($data['billing_address']) && is_array($data['billing_address'])) {
            $billingAddress = BillingAddressDTO::fromArray($data['billing_address']);
        }

        return new self(
            number: $data['number'] ?? '',
            holderName: $data['holder_name'] ?? '',
            expMonth: (int) ($data['exp_month'] ?? 0),
            expYear: (int) ($data['exp_year'] ?? 0),
            cvv: $data['cvv'] ?? '',
            billingAddress: $billingAddress,
        );
    }

    /**
     * Converte para array
     */
    public function toArray(): array
    {
        $data = [
            'number' => $this->number,
            'holder_name' => $this->holderName,
            'exp_month' => $this->expMonth,
            'exp_year' => $this->expYear,
            'cvv' => $this->cvv,
        ];

        if ($this->billingAddress) {
            $data['billing_address'] = $this->billingAddress->toArray();
        }

        return $data;
    }

    /**
     * Retorna número mascarado para log
     */
    public function getMaskedNumber(): string
    {
        if (strlen($this->number) < 4) {
            return '****';
        }
        
        return '****' . substr($this->number, -4);
    }
}
