<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\Treeal\TreealMtlsOptions;
use Tests\TestCase;

class TreealMtlsOptionsTest extends TestCase
{
    private string $pfxPath;
    private string $pemPath;
    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir();
        $this->pfxPath = $dir.DIRECTORY_SEPARATOR.'treeal-mtls-test.pfx';
        $this->pemPath = $dir.DIRECTORY_SEPARATOR.'treeal-mtls-test.pem';
        $this->keyPath = $dir.DIRECTORY_SEPARATOR.'treeal-mtls-test.key';

        file_put_contents($this->pfxPath, 'pfx');
        file_put_contents($this->pemPath, 'pem');
        file_put_contents($this->keyPath, 'key');
    }

    protected function tearDown(): void
    {
        foreach ([$this->pfxPath, $this->pemPath, $this->keyPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_is_configured_with_pfx(): void
    {
        config([
            'treeal.cert_format' => 'pfx',
            'treeal.cert_pfx_path' => $this->pfxPath,
            'treeal.cert_pem_path' => '',
            'treeal.cert_key_path' => '',
        ]);

        $this->assertTrue(TreealMtlsOptions::isConfigured());
    }

    public function test_is_configured_with_pem_pair(): void
    {
        config([
            'treeal.cert_format' => 'pem',
            'treeal.cert_pfx_path' => '',
            'treeal.cert_pem_path' => $this->pemPath,
            'treeal.cert_key_path' => $this->keyPath,
        ]);

        $this->assertTrue(TreealMtlsOptions::isConfigured());
    }

    public function test_is_not_configured_when_paths_empty(): void
    {
        config([
            'treeal.cert_format' => 'pfx',
            'treeal.cert_pfx_path' => '',
        ]);

        $this->assertFalse(TreealMtlsOptions::isConfigured());
    }

    public function test_build_pfx_returns_curl_options(): void
    {
        config([
            'treeal.cert_format' => 'pfx',
            'treeal.cert_pfx_path' => $this->pfxPath,
            'treeal.cert_pfx_password' => 'pass',
            'treeal.verify_ssl' => false,
        ]);

        $options = TreealMtlsOptions::build();

        $this->assertFalse($options['verify']);
        $this->assertSame('P12', $options['curl'][CURLOPT_SSLCERTTYPE]);
        $this->assertSame($this->pfxPath, $options['curl'][CURLOPT_SSLCERT]);
        $this->assertSame('pass', $options['curl'][CURLOPT_SSLCERTPASSWD]);
    }

    public function test_build_pem_returns_cert_and_key(): void
    {
        config([
            'treeal.cert_format' => 'pem',
            'treeal.cert_pem_path' => $this->pemPath,
            'treeal.cert_key_path' => $this->keyPath,
            'treeal.cert_key_password' => '',
            'treeal.verify_ssl' => true,
        ]);

        $options = TreealMtlsOptions::build();

        $this->assertTrue($options['verify']);
        $this->assertSame($this->pemPath, $options['cert']);
        $this->assertSame($this->keyPath, $options['ssl_key']);
    }

    public function test_build_throws_when_pfx_path_missing(): void
    {
        config([
            'treeal.cert_format' => 'pfx',
            'treeal.cert_pfx_path' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TREEAL_CERT_PFX_PATH');

        TreealMtlsOptions::build();
    }
}
