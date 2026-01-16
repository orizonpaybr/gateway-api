<?php

namespace App\DTO\PagarMeDTO;

/**
 * DTO para endereço de cobrança do cartão
 */
class BillingAddressDTO
{
    public function __construct(
        public string $line1,          // "Número, Rua, Bairro"
        public string $zipCode,
        public string $city,
        public string $state,
        public string $country = 'BR',
        public ?string $line2 = null,  // Complemento
    ) {}

    /**
     * Cria DTO a partir de array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            line1: $data['line_1'] ?? $data['line1'] ?? '',
            zipCode: $data['zip_code'] ?? $data['zipCode'] ?? '',
            city: $data['city'] ?? '',
            state: $data['state'] ?? '',
            country: $data['country'] ?? 'BR',
            line2: $data['line_2'] ?? $data['line2'] ?? null,
        );
    }

    /**
     * Cria DTO a partir de endereço brasileiro separado
     */
    public static function fromBrazilianAddress(
        string $street,
        string $number,
        string $neighborhood,
        string $city,
        string $state,
        string $zipCode,
        ?string $complement = null
    ): self {
        $line1 = "{$number}, {$street}, {$neighborhood}";
        
        return new self(
            line1: $line1,
            zipCode: preg_replace('/\D/', '', $zipCode),
            city: $city,
            state: $state,
            country: 'BR',
            line2: $complement,
        );
    }

    /**
     * Converte para array no formato Pagar.me
     */
    public function toArray(): array
    {
        $data = [
            'line_1' => $this->line1,
            'zip_code' => preg_replace('/\D/', '', $this->zipCode),
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
        ];

        if ($this->line2) {
            $data['line_2'] = $this->line2;
        }

        return $data;
    }
}
