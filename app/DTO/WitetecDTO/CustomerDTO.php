<?php

namespace App\DTO\WitetecDTO;

class CustomerDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $documentType,
        public string $document,
    ) {}
}

