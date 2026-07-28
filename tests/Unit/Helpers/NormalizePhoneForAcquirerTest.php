<?php

namespace Tests\Unit\Helpers;

use App\Helpers\Helper;
use Tests\TestCase;

/**
 * Telefone enviado à adquirente precisa ser DDD+número (10-11 dígitos), senão a
 * FluxPayments recusa o PIX com "Validation failed". Este teste garante que
 * número válido passa intacto e que número quebrado/ausente vira o fallback.
 */
class NormalizePhoneForAcquirerTest extends TestCase
{
    /** @test */
    public function celular_11_digitos_passa_intacto(): void
    {
        $this->assertSame('11999999999', Helper::normalizePhoneForAcquirer('(11) 99999-9999'));
    }

    /** @test */
    public function fixo_10_digitos_passa_intacto(): void
    {
        $this->assertSame('8133334444', Helper::normalizePhoneForAcquirer('81 3333-4444'));
    }

    /** @test */
    public function remove_codigo_do_pais_55(): void
    {
        $this->assertSame('11988887777', Helper::normalizePhoneForAcquirer('+55 11 98888-7777'));
    }

    /** @test */
    public function telefone_incompleto_9_digitos_vira_fallback(): void
    {
        // Caso real: cadastro "(98) 8296-882" = 9 dígitos → recusado pela Flux.
        $this->assertSame(Helper::FALLBACK_PHONE, Helper::normalizePhoneForAcquirer('(98) 8296-882'));
    }

    /** @test */
    public function vazio_ou_nulo_vira_fallback(): void
    {
        $this->assertSame(Helper::FALLBACK_PHONE, Helper::normalizePhoneForAcquirer(''));
        $this->assertSame(Helper::FALLBACK_PHONE, Helper::normalizePhoneForAcquirer(null));
    }

    /** @test */
    public function fallback_e_um_numero_valido(): void
    {
        // O próprio fallback tem que satisfazer a regra (10-11 dígitos).
        $len = strlen(Helper::FALLBACK_PHONE);
        $this->assertTrue($len === 10 || $len === 11);
    }
}
