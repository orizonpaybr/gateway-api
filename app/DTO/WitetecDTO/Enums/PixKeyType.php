<?php

namespace App\DTO\WitetecDTO\Enums;

enum PixKeyType: string
{
    case CPF   = 'CPF';
    case CNPJ  = 'CNPJ';
    case PHONE = 'PHONE';
    case EMAIL = 'EMAIL';
    case EVP   = 'EVP';
}