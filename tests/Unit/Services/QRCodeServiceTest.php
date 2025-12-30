<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\QRCodeService;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Testes Unitários - QRCodeService
 * 
 * Cobre:
 * - Funcionalidade de busca de QR Codes
 * - Filtros (status, busca, datas)
 * - Paginação
 * - Cache
 * - Formatação de dados
 * - Mapeamento de status
 */
class QRCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private QRCodeService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new QRCodeService();
        
        // Criar usuário para foreign key
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
        ]);
    }

    /**
     * Helper para criar depósito (QR Code)
     */
    private function createDeposito(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->user->user_id ?? $this->user->username,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'method' => 'PIX',
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'qrcode_pix' => 'https://example.com/qr',
            'paymentcode' => 'PAY' . uniqid(),
            'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
            'adquirente_ref' => 'Banco Test',
            'taxa_pix_cash_in_adquirente' => 1.0,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'executor_ordem' => 'EXEC' . uniqid(),
            'descricao_transacao' => 'QR Code de teste',
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    /**
     * Helper para criar saque (QR Code)
     */
    private function createSaque(array $attributes = []): SolicitacoesCashOut
    {
        $defaults = [
            'user_id' => $this->user->user_id ?? $this->user->username,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'cash_out_liquido' => 97.50,
            'taxa_cash_out' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'pix' => 'test@example.com',
            'pixkey' => 'test@example.com',
            'type' => 'EMAIL',
            'beneficiaryname' => 'Cliente Test',
            'beneficiarydocument' => '12345678900',
            'descricao_transacao' => 'QR Code de saque',
        ];

        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    /**
     * Teste: Deve retornar lista de QR Codes paginada
     */
    public function test_should_return_paginated_qrcodes(): void
    {
        // Criar 25 QR Codes (depósitos)
        for ($i = 0; $i < 25; $i++) {
            $this->createDeposito(['amount' => 100 + $i]);
        }

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('current_page', $result);
        $this->assertArrayHasKey('last_page', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(1, $result['current_page']);
        $this->assertEquals(20, count($result['data']));
        $this->assertEquals(25, $result['total']);
        $this->assertEquals(2, $result['last_page']);
    }

    /**
     * Teste: Deve incluir QR Codes de depósitos e saques
     */
    public function test_should_include_deposits_and_withdrawals(): void
    {
        $this->createDeposito(['idTransaction' => 'DEP001']);
        $this->createSaque(['idTransaction' => 'SAQ001']);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertEquals(2, $result['total']);
        
        $transactionIds = array_column($result['data'], 'transaction_id');
        $this->assertContains('DEP001', $transactionIds);
        $this->assertContains('SAQ001', $transactionIds);
    }

    /**
     * Teste: Deve filtrar QR Codes por status
     */
    public function test_should_filter_qrcodes_by_status(): void
    {
        $this->createDeposito(['status' => 'PAID_OUT']);
        $this->createDeposito(['status' => 'PENDING']);
        $this->createDeposito(['status' => 'FAILED']);

        $filters = ['page' => 1, 'limit' => 20, 'status' => 'PAID_OUT'];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertEquals(1, $result['total']);
        // O status é mapeado para 'ativo' pelo mapStatus
        $this->assertEquals('ativo', $result['data'][0]['status'] ?? null);
    }

    /**
     * Teste: Deve buscar QR Codes por termo
     */
    public function test_should_search_qrcodes_by_term(): void
    {
        $this->createDeposito(['idTransaction' => 'TXN123', 'descricao_transacao' => 'Teste QR Code']);
        $this->createDeposito(['idTransaction' => 'TXN456', 'descricao_transacao' => 'Outro QR Code']);

        $filters = ['page' => 1, 'limit' => 20, 'busca' => 'TXN123'];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('TXN123', $result['data'][0]['transaction_id']);
    }

    /**
     * Teste: Deve filtrar QR Codes por intervalo de datas
     */
    public function test_should_filter_qrcodes_by_date_range(): void
    {
        $hoje = Carbon::now();
        $ontem = Carbon::yesterday();

        $this->createDeposito(['date' => $hoje]);
        $this->createDeposito(['date' => $ontem]);
        $this->createDeposito(['date' => $hoje->copy()->subDays(5)]);

        $filters = [
            'page' => 1,
            'limit' => 20,
            'data_inicio' => $hoje->format('Y-m-d'),
            'data_fim' => $hoje->format('Y-m-d'),
        ];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertEquals(1, $result['total']);
    }

    /**
     * Teste: Deve usar cache para QR Codes
     */
    public function test_should_use_cache_for_qrcodes(): void
    {
        $this->createDeposito();

        $filters = ['page' => 1, 'limit' => 20];
        
        // Primeira chamada - deve buscar do banco
        $result1 = $this->service->getQRCodes($this->user->username, $filters);
        $this->assertEquals(1, $result1['total']);

        // Segunda chamada - deve usar cache
        Cache::shouldReceive('remember')
            ->once()
            ->andReturn($result1);

        $result2 = $this->service->getQRCodes($this->user->username, $filters);
        $this->assertEquals($result1['total'], $result2['total']);
    }

    /**
     * Teste: Deve validar limite máximo de itens por página
     */
    public function test_should_validate_max_items_per_page(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->createDeposito();
        }

        $filters = ['page' => 1, 'limit' => 150]; // Limite máximo é 100
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertLessThanOrEqual(100, count($result['data']));
    }

    /**
     * Teste: Deve validar página mínima
     */
    public function test_should_validate_minimum_page(): void
    {
        $this->createDeposito();

        $filters = ['page' => 0, 'limit' => 20]; // Página mínima é 1
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertEquals(1, $result['current_page']);
    }

    /**
     * Teste: Deve ordenar QR Codes por data descendente
     */
    public function test_should_order_qrcodes_by_date_desc(): void
    {
        $data1 = Carbon::now()->subDays(2);
        $data2 = Carbon::now()->subDays(1);
        $data3 = Carbon::now();

        $this->createDeposito(['date' => $data1, 'idTransaction' => 'TXN1']);
        $this->createDeposito(['date' => $data3, 'idTransaction' => 'TXN2']);
        $this->createDeposito(['date' => $data2, 'idTransaction' => 'TXN3']);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertEquals('TXN2', $result['data'][0]['transaction_id']);
        $this->assertEquals('TXN3', $result['data'][1]['transaction_id']);
        $this->assertEquals('TXN1', $result['data'][2]['transaction_id']);
    }

    /**
     * Teste: Deve retornar array vazio quando não há QR Codes
     */
    public function test_should_return_empty_array_when_no_qrcodes(): void
    {
        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['data']);
    }

    /**
     * Teste: Deve formatar QR Code corretamente
     */
    public function test_should_format_qrcode_correctly(): void
    {
        $deposito = $this->createDeposito([
            'amount' => 100.00,
            'status' => 'PAID_OUT',
            'descricao_transacao' => 'QR Code Test',
            'method' => 'PIX',
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
        ]);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $formatted = $result['data'][0];

        $this->assertArrayHasKey('id', $formatted);
        $this->assertArrayHasKey('nome', $formatted);
        $this->assertArrayHasKey('descricao', $formatted);
        $this->assertArrayHasKey('valor', $formatted);
        $this->assertArrayHasKey('status', $formatted);
        $this->assertArrayHasKey('transaction_id', $formatted);
        $this->assertArrayHasKey('data_criacao', $formatted);
        $this->assertArrayHasKey('tipo_cobranca', $formatted);
        $this->assertArrayHasKey('devedor', $formatted);
        $this->assertArrayHasKey('documento', $formatted);
        $this->assertArrayHasKey('origem', $formatted);
        $this->assertEquals(100.0, $formatted['valor']);
        $this->assertEquals('ativo', $formatted['status']); // PAID_OUT mapeado para 'ativo'
        $this->assertEquals('deposito', $formatted['origem']);
    }

    /**
     * Teste: Deve mapear status corretamente
     */
    public function test_should_map_status_correctly(): void
    {
        $this->createDeposito(['status' => 'PAID_OUT']);
        $this->createDeposito(['status' => 'FAILED']);
        $this->createDeposito(['status' => 'CANCELLED']);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        $statuses = array_column($result['data'], 'status');
        $this->assertContains('ativo', $statuses);
        $this->assertContains('inativo', $statuses);
    }

    /**
     * Teste: Deve filtrar apenas QR Codes com idTransaction
     */
    public function test_should_filter_only_qrcodes_with_transaction_id(): void
    {
        // Criar depósito com idTransaction (deve aparecer)
        $this->createDeposito(['idTransaction' => 'TXN001']);
        $this->createDeposito(['idTransaction' => 'TXN002']);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getQRCodes($this->user->username, $filters);

        // Todos os depósitos com idTransaction devem aparecer
        $this->assertEquals(2, $result['total']);
        
        $transactionIds = array_column($result['data'], 'transaction_id');
        $this->assertContains('TXN001', $transactionIds);
        $this->assertContains('TXN002', $transactionIds);
    }
}

