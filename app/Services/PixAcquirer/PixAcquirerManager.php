<?php

namespace App\Services\PixAcquirer;

use App\Models\Adquirente;
use App\Services\FluxPayments\FluxPaymentsAuthService;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;

class PixAcquirerManager
{
    /**
     * @var array<string, class-string<PixAcquirerInterface>>
     */
    private array $bindings = [];

    /**
     * Permite registrar adquirentes em runtime sem alterar controllers.
     *
     * Chave de registro = "provider" (família de serviço), ex: 'fluxpayments'.
     * Várias linhas de `adquirentes` (nominais) podem apontar para a mesma
     * família com `referencia` própria e credenciais próprias.
     *
     * @param class-string<PixAcquirerInterface> $serviceClass
     */
    public function register(string $reference, string $serviceClass): void
    {
        $this->bindings[strtolower(trim($reference))] = $serviceClass;
    }

    public function resolve(?string $reference): PixAcquirerInterface
    {
        $normalized = strtolower(trim((string) $reference));
        if ($normalized === '') {
            return new NullPixAcquirerService('unknown');
        }

        $row = Adquirente::where('referencia', $normalized)->first();
        $provider = strtolower(trim((string) ($row->provider ?? $normalized)));

        $serviceClass = $this->bindings[$provider] ?? null;
        if ($serviceClass === null) {
            return new NullPixAcquirerService($normalized);
        }

        if ($row && $row->credentials) {
            $custom = $this->buildWithCredentials($serviceClass, $row->credentials);
            if ($custom !== null) {
                return $custom;
            }
        }

        return app($serviceClass);
    }

    /**
     * Constrói uma instância isolada (fora do singleton do container) usando as
     * credenciais de uma nominal específica, em vez do .env global.
     *
     * @param  class-string<PixAcquirerInterface>  $serviceClass
     * @param  array<string, mixed>  $credentials
     */
    private function buildWithCredentials(string $serviceClass, array $credentials): ?PixAcquirerInterface
    {
        if ($serviceClass === FluxPaymentsPixAcquirerService::class) {
            return new FluxPaymentsPixAcquirerService(new FluxPaymentsAuthService($credentials));
        }

        return null;
    }
}
