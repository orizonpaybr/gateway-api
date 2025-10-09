<?php

namespace App\DTO\WitetecDTO;

class ItemDTO
{
    public function __construct(
        public string $title,
        public int $amount,
        public int $quantity,
        public bool $tangible,
        public string $externalRef,
    ) {}
}