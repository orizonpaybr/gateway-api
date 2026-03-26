<?php

namespace App\Services\PixAcquirer;

class PixAcquirerManager
{
    /**
     * @var array<string, class-string<PixAcquirerInterface>>
     */
    private array $bindings = [];

    /**
     * Permite registrar adquirentes em runtime sem alterar controllers.
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

        $serviceClass = $this->bindings[$normalized] ?? null;
        if ($serviceClass === null) {
            return new NullPixAcquirerService($normalized);
        }

        return app($serviceClass);
    }
}
