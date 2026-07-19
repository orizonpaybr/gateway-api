<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\TestCase;

/**
 * Testes do relatório de conciliação diária por usuário (Admin).
 *
 * Cenário base (mesma lógica explicada ao time):
 *   saldo_final = saldo_inicial + depósitos_líquidos − saques_debitados
 * Um usuário pode sacar mais do que depositou no dia se tinha saldo anterior.
 */
class AdminReconciliationReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): array
    {
        $admin = AuthTestHelper::createTestUser([
            'username' => 'admin_rec_' . uniqid(),
            'email' => 'admin_rec_' . uniqid() . '@example.com',
            'permission' => 3,
        ]);

        return [$admin, AuthTestHelper::generateTestToken($admin)];
    }

    private function insertDeposit(string $userId, float $amount, float $taxa, $date): void
    {
        DB::table('solicitacoes')->insert([
            'user_id' => $userId,
            'externalreference' => 'dep-' . uniqid(),
            'client_name' => 'Cliente Teste',
            'client_document' => '00000000000',
            'client_email' => 'cliente@example.com',
            'client_telefone' => '11999999999',
            'idTransaction' => 'txn-' . uniqid(),
            'amount' => $amount,
            'deposito_liquido' => $amount - $taxa,
            'taxa_cash_in' => $taxa,
            'taxa_pix_cash_in_adquirente' => 0,
            'taxa_pix_cash_in_valor_fixo' => 0,
            'qrcode_pix' => 'qr',
            'paymentcode' => 'code',
            'paymentCodeBase64' => 'base64',
            'adquirente_ref' => 'treeal',
            'descricao_transacao' => 'PIX',
            'date' => $date,
            'status' => 'PAID_OUT',
            'executor_ordem' => 'treeal',
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    private function insertWithdraw(string $userId, float $amount, float $taxa, $date): void
    {
        DB::table('solicitacoes_cash_out')->insert([
            'user_id' => $userId,
            'externalreference' => 'saq-' . uniqid(),
            'amount' => $amount,
            'valor_total_descontado' => $amount,
            'cash_out_liquido' => $amount - $taxa,
            'taxa_cash_out' => $taxa,
            'beneficiaryname' => 'Test',
            'beneficiarydocument' => '00000000000',
            'pix' => 'cpf',
            'pixkey' => '00000000000',
            'date' => $date,
            'status' => 'COMPLETED',
            'type' => 'PIX',
            'executor_ordem' => 'treeal',
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    public function test_requires_admin_permission(): void
    {
        $client = AuthTestHelper::createTestUser([
            'username' => 'client_rec_' . uniqid(),
            'email' => 'client_rec_' . uniqid() . '@example.com',
            'permission' => 1,
        ]);
        $token = AuthTestHelper::generateTestToken($client);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/reports/reconciliation?periodo=hoje')
            ->assertStatus(403);
    }

    public function test_report_calculates_profit_and_balances(): void
    {
        Cache::flush();
        [$admin, $token] = $this->makeAdmin();

        // Usuário com saldo atual de R$ 50 após os movimentos de hoje:
        // saldo_inicial = 50 - 98.50 (dep líquido) + 60 (saque) = 11.50
        $user = AuthTestHelper::createTestUser([
            'username' => 'monica_' . uniqid(),
            'email' => 'monica_' . uniqid() . '@example.com',
            'saldo' => 50.00,
            'saldo_afiliado' => 0,
        ]);

        // Depósito hoje: bruto 100, taxa 1.50, líquido 98.50
        $this->insertDeposit($user->username, 100.00, 1.50, now());
        // Saque hoje: debita 60, taxa 1.50, pago 58.50
        $this->insertWithdraw($user->username, 60.00, 1.50, now());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/reports/reconciliation?periodo=hoje')
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');

        // Resumo: lucro = 1.50 (depósito) + 1.50 (saque)
        $this->assertEqualsWithDelta(1.50, $data['resumo']['lucro_depositos'], 0.001);
        $this->assertEqualsWithDelta(1.50, $data['resumo']['lucro_saques'], 0.001);
        $this->assertEqualsWithDelta(3.00, $data['resumo']['lucro_total'], 0.001);
        $this->assertSame(1, $data['resumo']['usuarios_ativos']);

        // Linha do usuário
        $linha = collect($data['linhas'])->firstWhere('user_id', $user->username);
        $this->assertNotNull($linha);
        $this->assertEqualsWithDelta(98.50, $linha['depositos_liquido'], 0.001);
        $this->assertEqualsWithDelta(60.00, $linha['saques_debitado'], 0.001);
        $this->assertEqualsWithDelta(58.50, $linha['saques_pago'], 0.001);
        $this->assertEqualsWithDelta(3.00, $linha['lucro'], 0.001);

        // Reconstrução do saldo: final = saldo atual (50); inicial = 50 - 98.50 + 60 = 11.50
        $this->assertEqualsWithDelta(50.00, $linha['saldo_final'], 0.001);
        $this->assertEqualsWithDelta(11.50, $linha['saldo_inicial'], 0.001);
    }

    public function test_report_shows_withdraw_greater_than_daily_deposits_with_prior_balance(): void
    {
        Cache::flush();
        [$admin, $token] = $this->makeAdmin();

        // Caso "monica": ontem depositou 100 (sem sacar); hoje vendeu 300 e sacou 400.
        // Saldo atual = 0 → hoje: inicial 100 (de ontem), final 0.
        $user = AuthTestHelper::createTestUser([
            'username' => 'previa_' . uniqid(),
            'email' => 'previa_' . uniqid() . '@example.com',
            'saldo' => 0,
            'saldo_afiliado' => 0,
        ]);

        $this->insertDeposit($user->username, 100.00, 0.00, now()->subDay());
        $this->insertDeposit($user->username, 300.00, 0.00, now());
        $this->insertWithdraw($user->username, 400.00, 0.00, now());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/reports/reconciliation?periodo=7dias')
            ->assertOk();

        $linhas = collect($response->json('data.linhas'))
            ->where('user_id', $user->username)
            ->values();

        $this->assertCount(2, $linhas);

        // Hoje (primeira, ordenação desc): inicial 100, dep 300, saque 400, final 0
        $hoje = $linhas[0];
        $this->assertEqualsWithDelta(100.00, $hoje['saldo_inicial'], 0.001);
        $this->assertEqualsWithDelta(300.00, $hoje['depositos_liquido'], 0.001);
        $this->assertEqualsWithDelta(400.00, $hoje['saques_debitado'], 0.001);
        $this->assertEqualsWithDelta(0.00, $hoje['saldo_final'], 0.001);

        // Ontem: inicial 0, dep 100, final 100
        $ontem = $linhas[1];
        $this->assertEqualsWithDelta(0.00, $ontem['saldo_inicial'], 0.001);
        $this->assertEqualsWithDelta(100.00, $ontem['saldo_final'], 0.001);
    }

    public function test_export_returns_csv_download(): void
    {
        Cache::flush();
        [$admin, $token] = $this->makeAdmin();

        $user = AuthTestHelper::createTestUser([
            'username' => 'csv_' . uniqid(),
            'email' => 'csv_' . uniqid() . '@example.com',
            'saldo' => 10,
        ]);
        $this->insertDeposit($user->username, 50.00, 1.50, now());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/reports/reconciliation/export?periodo=hoje');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertMatchesRegularExpression('/coratri_\d{4}_\d{2}_\d{2}\.csv/', $disposition);

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Saldo Inicial (dia)', $csv);
        $this->assertStringContainsString($user->username, $csv);
        $this->assertStringContainsString('TOTAIS', $csv);
    }

    public function test_rejects_period_longer_than_31_days(): void
    {
        [$admin, $token] = $this->makeAdmin();

        $inicio = now()->subDays(60)->format('Y-m-d');
        $fim = now()->format('Y-m-d');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/admin/reports/reconciliation?periodo={$inicio}:{$fim}")
            ->assertStatus(422);
    }
}
