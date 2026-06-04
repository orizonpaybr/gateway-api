<?php

namespace App\Rules;

use App\Traits\IPManagementTrait;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedIpEntry implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Formato de IP inválido.');

            return;
        }

        if (! IPManagementTrait::isValidIP(trim($value))) {
            $fail('Informe um IPv4 válido, range CIDR (ex: 74.220.48.0/24) ou wildcard (ex: 192.168.1.*).');
        }
    }
}
