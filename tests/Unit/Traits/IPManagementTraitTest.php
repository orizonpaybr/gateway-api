<?php

namespace Tests\Unit\Traits;

use App\Traits\IPManagementTrait;
use PHPUnit\Framework\TestCase;

class IPManagementTraitTest extends TestCase
{

    /** @test */
    public function aceita_ip_fixo_na_verificacao(): void
    {
        $allowed = ['203.0.113.50'];

        $this->assertTrue(IPManagementTrait::checkIPInList('203.0.113.50', $allowed));
        $this->assertFalse(IPManagementTrait::checkIPInList('203.0.113.51', $allowed));
    }

    /** @test */
    public function aceita_cliente_dentro_de_cidr_24(): void
    {
        $allowed = ['74.220.48.0/24'];

        $this->assertTrue(IPManagementTrait::checkIPInList('74.220.48.1', $allowed));
        $this->assertTrue(IPManagementTrait::checkIPInList('74.220.48.254', $allowed));
        $this->assertFalse(IPManagementTrait::checkIPInList('74.220.49.1', $allowed));
    }

    /** @test */
    public function normaliza_cidr_para_endereco_de_rede(): void
    {
        $this->assertSame(
            '74.220.48.0/24',
            IPManagementTrait::normalizeAllowedIP('74.220.48.17/24')
        );
    }

    /** @test */
    public function valida_formatos_de_entrada(): void
    {
        $this->assertTrue(IPManagementTrait::isValidIP('192.168.1.1'));
        $this->assertTrue(IPManagementTrait::isValidIP('74.220.48.0/24'));
        $this->assertTrue(IPManagementTrait::isValidIP('10.0.0.*'));

        $this->assertFalse(IPManagementTrait::isValidIP('999.1.1.1'));
        $this->assertFalse(IPManagementTrait::isValidIP('74.220.48.0/33'));
        $this->assertFalse(IPManagementTrait::isValidIP('not-an-ip'));
    }

    /** @test */
    public function lista_mista_cidr_e_ip_fixo_mantem_compatibilidade(): void
    {
        $allowed = ['198.51.100.10', '74.220.56.0/24'];

        $this->assertTrue(IPManagementTrait::checkIPInList('198.51.100.10', $allowed));
        $this->assertTrue(IPManagementTrait::checkIPInList('74.220.56.200', $allowed));
        $this->assertFalse(IPManagementTrait::checkIPInList('198.51.100.11', $allowed));
    }

    /** @test */
    public function parse_allowed_ips_aceita_json_legado(): void
    {
        $parsed = IPManagementTrait::parseAllowedIPs(
            '["203.0.113.1","74.220.48.0/24"]'
        );

        $this->assertSame(['203.0.113.1', '74.220.48.0/24'], $parsed);
    }
}
