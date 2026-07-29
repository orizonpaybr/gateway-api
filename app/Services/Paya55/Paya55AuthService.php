<?php

namespace App\Services\Paya55;

use App\Services\FluxPayments\FluxPaymentsAuthService;

/**
 * Basic Auth da Paya55 (A55) — mesma mecânica da FluxPayments (apiKey:publicKey
 * em base64 + User-Agent obrigatório), lendo config/paya55.php.
 *
 * @see https://app.paya55.com/docs
 */
class Paya55AuthService extends FluxPaymentsAuthService
{
    /**
     * @param  array<string, mixed>|null  $credentials  Credenciais da nominal (adquirentes.credentials).
     */
    public function __construct(?array $credentials = null)
    {
        parent::__construct($credentials, 'paya55');
    }
}
