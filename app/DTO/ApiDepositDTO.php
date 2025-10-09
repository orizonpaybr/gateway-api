<?php

namespace App\DTO;

use App\Models\User;

class ApiDepositDTO
{
    public function __construct(
        public string $token,
        public string $secret,
        public float $amount,
        public string $debtor_name,
        public string $email,
        public string $debtor_document_number,
        public string $phone,
        public string $method_pay,
        public string $postback,
        public User $user,
    ) {}
}

