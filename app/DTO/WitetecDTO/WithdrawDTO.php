<?php

namespace App\DTO\WitetecDTO;

use App\DTO\WitetecDTO\Enums\PixKeyType;
use App\DTO\WitetecDTO\Enums\WithdrawMethod;


class WithdrawDTO 
{
    public function __construct(
    public int $amount,
    public string $pixKey,
    public PixKeyType $pixKeyType,
    public string $method 
    ) {}
}