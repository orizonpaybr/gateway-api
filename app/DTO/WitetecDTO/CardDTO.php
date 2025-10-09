<?php

namespace App\DTO\WitetecDTO;


class CardDTO
{
    public function __construct(
        public string $number,
        public string $holderName,
        public string $holderDocument,
        public int $expirationMonth,
        public int $expirationYear,
        public string $cvv,
    ) {}
}