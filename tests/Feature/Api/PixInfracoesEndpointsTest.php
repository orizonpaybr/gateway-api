<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\TestCase;

/**
 * Endpoints do lojista para infrações Pix (MED):
 *  - GET  /api/pix/infracoes           lista as infrações do próprio usuário.
 *  - GET  /api/pix/infracoes/{id}      detalhe (404 se não for dele).
 *  - POST /api/pix/infracoes/{id}/defense  envia defesa à adquirente (Treeal Contas).
 *
 * A submissão de defesa é validada com o cliente HTTP da Treeal "mockado" (Http::fake),
 * já que não há ambiente de homologação para testar a chamada real.
 */
class PixInfracoesEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function insertInfraction(string $username, array $overrides = []): int
    {
        $now = Carbon::now();

        return (int) DB::table('pix_infracoes')->insertGetId(array_merge([
            'user_id' => $username,
            'transaction_id' => 'TXN_'.uniqid(),
            'status' => 'PENDENTE',
            'tipo' => 'fraude',
            'descricao' => 'Infração de teste',
            'valor' => 100.00,
            'end_to_end' => 'E'.uniqid(),
            'data_criacao' => $now,
            'data_limite' => $now->copy()->addDays(7),
            'detalhes' => json_encode(['infractionId' => 'inf-'.uniqid()]),
            'provider' => 'treeal',
            'provider_infraction_id' => 'inf-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function configureTreeal(): void
    {
        config([
            'treeal_contas.client_id' => 'test-client',
            'treeal_contas.client_secret' => 'test-secret',
            'treeal_contas.base_url' => 'https://treeal.test',
            'treeal_contas.cert_format' => 'pfx',
            'treeal_contas.cert_pfx_path' => 'storage/test/fake-cert.pfx',
            'treeal_contas.cert_pfx_password' => 'x',
        ]);

        // Evita a chamada OAuth real: token já em cache (store array nos testes).
        Cache::put('treeal_contas_access_token', 'fake-token', now()->addHour());
    }

    public function test_index_lists_only_own_infractions(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'mine_'.uniqid(),
            'email' => 'mine_'.uniqid().'@example.com',
        ]);
        $other = AuthTestHelper::createTestUser([
            'username' => 'other_'.uniqid(),
            'email' => 'other_'.uniqid().'@example.com',
        ]);

        $this->insertInfraction($user->username, ['end_to_end' => 'E_MINE_1']);
        $this->insertInfraction($user->username, ['end_to_end' => 'E_MINE_2']);
        $this->insertInfraction($other->username, ['end_to_end' => 'E_OTHER_1']);

        $token = AuthTestHelper::generateTestToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/pix/infracoes');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2);

        $e2es = collect($response->json('data.data'))->pluck('end_to_end')->all();
        $this->assertContains('E_MINE_1', $e2es);
        $this->assertContains('E_MINE_2', $e2es);
        $this->assertNotContains('E_OTHER_1', $e2es);
    }

    public function test_index_maps_resolvida_and_estorno_by_analysis_result(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'idx_'.uniqid(),
            'email' => 'idx_'.uniqid().'@example.com',
        ]);

        $this->insertInfraction($user->username, [
            'end_to_end' => 'E_IDX_WIN',
            'status' => 'RESOLVIDA',
            'analysis_result' => 'DISAGREED',
            'detalhes' => json_encode(['analysisResult' => 'DISAGREED']),
        ]);
        $this->insertInfraction($user->username, [
            'end_to_end' => 'E_IDX_LOSE',
            'status' => 'RESOLVIDA',
            'analysis_result' => 'AGREED',
            'detalhes' => json_encode(['analysisResult' => 'AGREED']),
        ]);

        $token = AuthTestHelper::generateTestToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/pix/infracoes');

        $response->assertOk();

        $byE2e = collect($response->json('data.data'))->keyBy('end_to_end');
        $this->assertSame('Resolvida', $byE2e->get('E_IDX_WIN')['status']);
        $this->assertSame('Estorno', $byE2e->get('E_IDX_LOSE')['status']);
    }

    public function test_show_returns_detail_and_404_for_foreign(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'show_'.uniqid(),
            'email' => 'show_'.uniqid().'@example.com',
        ]);
        $other = AuthTestHelper::createTestUser([
            'username' => 'showx_'.uniqid(),
            'email' => 'showx_'.uniqid().'@example.com',
        ]);

        $id = $this->insertInfraction($user->username, [
            'end_to_end' => 'E_SHOW_1',
            'tipo' => 'refund_request',
            'detalhes' => json_encode([
                'infractionId' => 'inf-show-1',
                'status' => 'WAITING_PSP',
                'analysisResult' => null,
                'reportedBy' => 'DEBITED_PARTICIPANT',
            ]),
        ]);
        $foreignId = $this->insertInfraction($other->username);

        $token = AuthTestHelper::generateTestToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/pix/infracoes/'.$id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.end_to_end', 'E_SHOW_1')
            ->assertJsonPath('data.tipo_legivel', 'Solicitação de devolução (MED)')
            ->assertJsonPath('data.detalhes_adicionais.0.label', 'ID na adquirente (Treeal)')
            ->assertJsonPath('data.pode_apresentar_defesa', true)
            ->assertJsonPath('data.defesa_enviada_para', 'Treeal (adquirente Pix / MED)');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/pix/infracoes/'.$foreignId)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_show_resolvida_when_merchant_wins_disagreed(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'win_'.uniqid(),
            'email' => 'win_'.uniqid().'@example.com',
        ]);

        $id = $this->insertInfraction($user->username, [
            'status' => 'RESOLVIDA',
            'analysis_result' => 'DISAGREED',
            'detalhes' => json_encode([
                'infractionId' => 'inf-win-1',
                'status' => 'CLOSED',
                'analysisResult' => 'DISAGREED',
                'reportedBy' => 'DEBITED_PARTICIPANT',
            ]),
        ]);

        $token = AuthTestHelper::generateTestToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/pix/infracoes/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', 'Resolvida')
            ->assertJsonPath('data.favoravel_lojista', true)
            ->assertJsonPath('data.desfecho_titulo', 'Contestação encerrada a seu favor')
            ->assertJsonPath('data.pode_apresentar_defesa', false);
    }

    public function test_show_estorno_when_payer_wins_agreed(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'lose_'.uniqid(),
            'email' => 'lose_'.uniqid().'@example.com',
        ]);

        $id = $this->insertInfraction($user->username, [
            'status' => 'RESOLVIDA',
            'analysis_result' => 'AGREED',
            'detalhes' => json_encode([
                'infractionId' => 'inf-lose-1',
                'status' => 'CLOSED',
                'analysisResult' => 'AGREED',
                'reportedBy' => 'DEBITED_PARTICIPANT',
            ]),
        ]);

        $token = AuthTestHelper::generateTestToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/pix/infracoes/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', 'Estorno')
            ->assertJsonPath('data.favoravel_lojista', false)
            ->assertJsonPath('data.desfecho_titulo', 'Valor estornado ao pagador')
            ->assertJsonPath('data.pode_apresentar_defesa', false);
    }

    public function test_defense_requires_authentication(): void
    {
        $this->postJson('/api/pix/infracoes/1/defense', ['defense' => 'x'])
            ->assertStatus(401);
    }

    public function test_defense_without_provider_link_returns_422(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'nolink_'.uniqid(),
            'email' => 'nolink_'.uniqid().'@example.com',
        ]);

        $id = $this->insertInfraction($user->username, ['provider_infraction_id' => null]);
        $token = AuthTestHelper::generateTestToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/pix/infracoes/'.$id.'/defense', ['defense' => 'Defesa válida do lojista.'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_defense_success_forwards_to_treeal_and_updates_status(): void
    {
        $this->configureTreeal();

        Http::fake([
            'treeal.test/*' => Http::response(['protocol' => 'PROT-123', 'status' => 'DEFENDED'], 200),
        ]);

        $user = AuthTestHelper::createTestUser([
            'username' => 'def_'.uniqid(),
            'email' => 'def_'.uniqid().'@example.com',
        ]);

        $id = $this->insertInfraction($user->username, [
            'provider_infraction_id' => 'inf-defense-1',
            'status' => 'PENDENTE',
        ]);

        $token = AuthTestHelper::generateTestToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/pix/infracoes/'.$id.'/defense', [
                'defense' => 'Operação legítima: cliente reconhecido e comprovante anexado.',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('pix_infracoes', [
            'id' => $id,
            'status' => 'EM_ANALISE',
        ]);

        // Confirma que a defesa foi encaminhada ao endpoint correto da adquirente.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/infractions/inf-defense-1/defense');
        });
    }

    public function test_defense_validation_rejects_empty_text(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'defval_'.uniqid(),
            'email' => 'defval_'.uniqid().'@example.com',
        ]);

        $id = $this->insertInfraction($user->username);
        $token = AuthTestHelper::generateTestToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/pix/infracoes/'.$id.'/defense', ['defense' => ''])
            ->assertStatus(422);
    }

    public function test_index_full_page_sets_next_cursor_without_error(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'page_'.uniqid(),
            'email' => 'page_'.uniqid().'@example.com',
        ]);

        $now = Carbon::now();
        for ($i = 0; $i < 20; $i++) {
            $this->insertInfraction($user->username, [
                'end_to_end' => 'E_PAGE_'.$i,
                'data_criacao' => null,
                'created_at' => $now->copy()->subMinutes($i),
            ]);
        }

        $token = AuthTestHelper::generateTestToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/pix/infracoes?page=1&limit=20')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 20)
            ->assertJsonPath('data.next_cursor', $now->copy()->subMinutes(19)->toDateTimeString());
    }
}
