<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes do endpoint GET user/affiliate-link (generateAffiliateLink)
 *
 * Cobre: retorno 200 com link e código quando autenticado, 401 sem token.
 */
class AffiliateLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function affiliate_link_retorna_200_e_dados_com_jwt(): void
    {
        config(['app.affiliado_url' => 'https://app.example.com']);
        $user = AuthTestHelper::createTestUser([
            'username' => 'affuser_' . uniqid(),
            'email' => 'aff_' . uniqid() . '@example.com',
            'status' => 1,
        ]);

        $token = AuthTestHelper::generateTestToken($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/affiliate-link');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'affiliate_code',
                    'affiliate_link',
                ],
            ]);
        $this->assertStringContainsString('ref=', $response->json('data.affiliate_link'));
    }

    /** @test */
    public function affiliate_link_retorna_401_sem_token(): void
    {
        $response = $this->getJson('/api/user/affiliate-link');
        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    /** @test */
    public function affiliate_link_retorna_mesmo_codigo_em_chamadas_seguidas_se_ja_existir(): void
    {
        config(['app.affiliado_url' => 'https://app.example.com']);
        $user = AuthTestHelper::createTestUser([
            'username' => 'affcode',
            'email' => 'affcode@example.com',
            'affiliate_code' => 'AFFC1234',
            'is_affiliate' => true,
            'status' => 1,
        ]);

        $token = AuthTestHelper::generateTestToken($user);

        $r1 = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/user/affiliate-link');
        $r2 = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/user/affiliate-link');

        $this->assertEquals($r1->json('data.affiliate_code'), $r2->json('data.affiliate_code'));
        $this->assertEquals('AFFC1234', $r1->json('data.affiliate_code'));
    }
}
