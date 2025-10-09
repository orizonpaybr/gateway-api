<?php

namespace App\DTO\WitetecDTO\Enums;

enum DepositMethod: string 
{
    case PIX = "PIX";
    case BOLETO = "BOLETO";
    case CREDIT_CARD = "CREDIT_CARD";
}