<?php

namespace App\DTO\PagarMeDTO;

/**
 * DTO para dados de autenticação 3D Secure
 * 
 * Usado quando a autenticação 3DS é feita por um MPI externo (third_party).
 * Se usar MPI da Pagar.me, não é necessário preencher estes dados.
 */
class ThreeDSecureDTO
{
    public function __construct(
        public string $eci,
        public string $cavv,
        public ?string $dsTransactionId = null,
        public ?string $transactionId = null,
        public string $version = '2',
    ) {}

    /**
     * Cria DTO a partir de array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eci: $data['eci'] ?? '',
            cavv: $data['cavv'] ?? '',
            dsTransactionId: $data['ds_transaction_id'] ?? null,
            transactionId: $data['transaction_id'] ?? null,
            version: $data['version'] ?? '2',
        );
    }

    /**
     * Converte para array
     */
    public function toArray(): array
    {
        return array_filter([
            'eci' => $this->eci,
            'cavv' => $this->cavv,
            'ds_transaction_id' => $this->dsTransactionId,
            'transaction_id' => $this->transactionId,
            'version' => $this->version,
        ], fn($value) => $value !== null);
    }

    /**
     * Verifica se os dados são válidos
     */
    public function isValid(): bool
    {
        return !empty($this->eci) && !empty($this->cavv);
    }
}
