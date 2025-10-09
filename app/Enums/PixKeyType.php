<?php

namespace App\Enums;

enum PixKeyType: string
{
    case CPF = 'cpf';
    case EMAIL = 'email';
    case PHONE = 'telefone';
    case RANDOM = 'aleatoria';
}