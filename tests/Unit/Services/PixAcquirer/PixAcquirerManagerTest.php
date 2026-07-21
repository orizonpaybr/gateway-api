<?php

namespace Tests\Unit\Services\PixAcquirer;

use App\Models\Adquirente;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use App\Services\PixAcquirer\NullPixAcquirerService;
use App\Services\PixAcquirer\PixAcquirerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre a resolução multi-conta ("nominais"): várias linhas de `adquirentes`
 * podem compartilhar o mesmo provider/serviço, cada uma com suas próprias
 * credenciais, sem quebrar o comportamento das linhas legadas (credentials=null
 * continua lendo do .env global).
 */
class PixAcquirerManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_sem_referencia_retorna_null_service(): void
    {
        $manager = app(PixAcquirerManager::class);

        $this->assertInstanceOf(NullPixAcquirerService::class, $manager->resolve(null));
        $this->assertInstanceOf(NullPixAcquirerService::class, $manager->resolve(''));
    }

    public function test_resolve_referencia_desconhecida_retorna_null_service(): void
    {
        $manager = app(PixAcquirerManager::class);

        $this->assertInstanceOf(NullPixAcquirerService::class, $manager->resolve('nao-existe'));
    }

    public function test_resolve_linha_legada_sem_credenciais_usa_singleton_global(): void
    {
        // A migration de seed (2026_07_13_183800) já cria a linha 'fluxpayments'
        // legada (credentials=null) — é ela que precisa continuar caindo no
        // singleton/.env global, sem regressão.
        $legacy = Adquirente::where('referencia', 'fluxpayments')->firstOrFail();
        $this->assertNull($legacy->credentials);

        $manager = app(PixAcquirerManager::class);
        $service = $manager->resolve('fluxpayments');

        $this->assertInstanceOf(FluxPaymentsPixAcquirerService::class, $service);
        $this->assertSame($service, $manager->resolve('fluxpayments'));
    }

    public function test_resolve_nominal_com_credenciais_proprias_usa_instancia_isolada(): void
    {
        Adquirente::create([
            'adquirente' => 'FluxPayments (Vendas Digitais)',
            'status' => true,
            'url' => 'https://api.fluxpaymentss.com',
            'referencia' => 'fluxpayments-vendas-digitais',
            'provider' => 'fluxpayments',
            'credentials' => [
                'api_key' => 'nominal_key',
                'public_key' => 'nominal_public',
            ],
            'is_default' => false,
        ]);

        $manager = app(PixAcquirerManager::class);
        $service = $manager->resolve('fluxpayments-vendas-digitais');

        $this->assertInstanceOf(FluxPaymentsPixAcquirerService::class, $service);
        $this->assertTrue($service->isActive());
        // Cada nominal precisa gerar uma instância própria — nunca o singleton global.
        $this->assertNotSame(app(FluxPaymentsPixAcquirerService::class), $service);
    }

    public function test_duas_nominais_do_mesmo_provider_nao_compartilham_credenciais(): void
    {
        Adquirente::create([
            'adquirente' => 'FluxPayments (Vendas Digitais)',
            'status' => true,
            'url' => 'https://api.fluxpaymentss.com',
            'referencia' => 'fluxpayments-vendas-digitais',
            'provider' => 'fluxpayments',
            'credentials' => ['api_key' => 'key_a', 'public_key' => 'pub_a'],
            'is_default' => false,
        ]);

        Adquirente::create([
            'adquirente' => 'FluxPayments (Vendas Alguma Coisa)',
            'status' => true,
            'url' => 'https://api.fluxpaymentss.com',
            'referencia' => 'fluxpayments-vendas-2',
            'provider' => 'fluxpayments',
            'credentials' => ['api_key' => 'key_b', 'public_key' => 'pub_b'],
            'is_default' => false,
        ]);

        $manager = app(PixAcquirerManager::class);

        $serviceA = $manager->resolve('fluxpayments-vendas-digitais');
        $serviceB = $manager->resolve('fluxpayments-vendas-2');

        $this->assertNotSame($serviceA, $serviceB);

        $authProperty = new \ReflectionProperty(FluxPaymentsPixAcquirerService::class, 'auth');
        $headersA = $authProperty->getValue($serviceA)->authHeaders();
        $headersB = $authProperty->getValue($serviceB)->authHeaders();

        $this->assertNotSame($headersA['Authorization'], $headersB['Authorization']);
        $this->assertSame('Basic '.base64_encode('key_a:pub_a'), $headersA['Authorization']);
        $this->assertSame('Basic '.base64_encode('key_b:pub_b'), $headersB['Authorization']);
    }
}
