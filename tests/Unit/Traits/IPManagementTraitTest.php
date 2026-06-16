<?php

namespace Tests\Unit\Traits;

use App\Traits\IPManagementTrait;
use Illuminate\Http\Request;
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

    /** @test */
    public function get_client_ip_prioriza_cf_connecting_ip_sobre_ip_da_cloudflare(): void
    {
        $request = Request::create('/api/pixout', 'POST', server: [
            'REMOTE_ADDR' => '172.71.6.145',
            'HTTP_CF_CONNECTING_IP' => '45.233.86.55',
        ]);

        $this->assertSame('45.233.86.55', IPManagementTrait::getClientIP($request));
    }

    /** @test */
    public function get_client_ip_confia_em_xff_quando_peer_e_cloudflare(): void
    {
        // REMOTE_ADDR é um range Cloudflare (173.245.48.0/20) e não há CF-Connecting-IP.
        $request = Request::create('/api/pixout', 'POST', server: [
            'REMOTE_ADDR' => '173.245.48.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50, 172.71.6.145',
        ]);

        $this->assertSame('203.0.113.50', IPManagementTrait::getClientIP($request));
    }

    /** @test */
    public function get_client_ip_ignora_cf_connecting_ip_forjado_em_conexao_direta(): void
    {
        // Atacante batendo direto no IP de origem (REMOTE_ADDR não é Cloudflare)
        // tentando se passar por um IP da allowlist via header forjado.
        $request = Request::create('/api/pixout', 'POST', server: [
            'REMOTE_ADDR' => '45.10.10.10',
            'HTTP_CF_CONNECTING_IP' => '45.233.86.55',
            'HTTP_X_FORWARDED_FOR' => '45.233.86.55',
        ]);

        // Deve retornar o IP real do atacante, não o forjado.
        $this->assertSame('45.10.10.10', IPManagementTrait::getClientIP($request));
    }

    /** @test */
    public function get_client_ip_retorna_remote_addr_quando_nginx_real_ip_ja_resolveu(): void
    {
        // Nginx com real_ip ativo reescreve REMOTE_ADDR para o IP real do cliente
        // (que não está no range Cloudflare). Sem headers a confiar, usamos REMOTE_ADDR.
        $request = Request::create('/api/pixout', 'POST', server: [
            'REMOTE_ADDR' => '45.233.86.55',
        ]);

        $this->assertSame('45.233.86.55', IPManagementTrait::getClientIP($request));
    }

    /** @test */
    public function is_trusted_proxy_reconhece_ranges_cloudflare(): void
    {
        $this->assertTrue(IPManagementTrait::isTrustedProxy('172.71.6.145'));   // 172.64.0.0/13
        $this->assertTrue(IPManagementTrait::isTrustedProxy('162.159.122.27')); // 162.158.0.0/15
        $this->assertTrue(IPManagementTrait::isTrustedProxy('2400:cb00::1'));   // 2400:cb00::/32
        $this->assertFalse(IPManagementTrait::isTrustedProxy('45.233.86.55'));
        $this->assertFalse(IPManagementTrait::isTrustedProxy('8.8.8.8'));
    }

    /** @test */
    public function aceita_ipv6_fixo_na_verificacao(): void
    {
        $allowed = ['2804:219c:21c:3500:9dc7:5aa:7ee9:f56b'];

        $this->assertTrue(IPManagementTrait::checkIPInList('2804:219c:21c:3500:9dc7:5aa:7ee9:f56b', $allowed));
        $this->assertFalse(IPManagementTrait::checkIPInList('2804:219c:21c:3500::1', $allowed));
    }

    /** @test */
    public function aceita_cliente_dentro_de_cidr_ipv6_64(): void
    {
        // Faixa /64 é estável mesmo quando o sufixo do IPv6 residencial roda.
        $allowed = ['2804:219c:21c:3500::/64'];

        $this->assertTrue(IPManagementTrait::checkIPInList('2804:219c:21c:3500:9dc7:5aa:7ee9:f56b', $allowed));
        $this->assertTrue(IPManagementTrait::checkIPInList('2804:219c:21c:3500::1', $allowed));
        $this->assertFalse(IPManagementTrait::checkIPInList('2804:219c:21c:3501::1', $allowed));
    }

    /** @test */
    public function ipv4_nao_casa_com_cidr_ipv6_e_vice_versa(): void
    {
        $this->assertFalse(IPManagementTrait::checkIPInList('45.233.86.55', ['2804:219c:21c:3500::/64']));
        $this->assertFalse(IPManagementTrait::checkIPInList('2804:219c:21c:3500::1', ['45.233.86.0/24']));
    }

    /** @test */
    public function valida_e_normaliza_entradas_ipv6(): void
    {
        $this->assertTrue(IPManagementTrait::isValidIP('2804:219c:21c:3500:9dc7:5aa:7ee9:f56b'));
        $this->assertTrue(IPManagementTrait::isValidIP('2804:219c:21c:3500::/64'));
        $this->assertFalse(IPManagementTrait::isValidIP('2804:219c:21c:3500::/129'));

        $this->assertSame(
            '2804:219c:21c:3500::/64',
            IPManagementTrait::normalizeAllowedIP('2804:219c:21c:3500:9dc7:5aa:7ee9:f56b/64')
        );
    }
}
