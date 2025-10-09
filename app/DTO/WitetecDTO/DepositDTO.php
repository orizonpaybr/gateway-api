<?php

namespace   App\DTO\WitetecDTO;

use App\DTO\WitetecDTO\CustomerDTO;
use App\DTO\WitetecDTO\Enums\DepositMethod;


class DepositDTO
{
    /**
     * @param ItemDTO[] $items
     */
    public function __construct(
        public int $amount,
        public DepositMethod $method,
        public CustomerDTO $customer,
        public array $items,
        public ?CardDTO $card
    ) {}
}