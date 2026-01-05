<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserAccountDataSeeder extends Seeder
{
    /**
     * Completar dados da conta dos usuários
     * Incluindo taxas personalizadas, webhooks, etc.
     */
    public function run(): void
    {
        // Buscar usuários criados pelos seeds
        $users = DB::table('users')
            ->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])
            ->get();

        if ($users->isEmpty()) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        foreach ($users as $user) {
            $isGerente = in_array($user->username, ['gerente1', 'gerente2']);
            
            // Dados completos da conta
            // Verificar quais colunas existem antes de atualizar
            $schema = DB::getSchemaBuilder();
            $updateData = [];
            
            // Informações Pessoais/Empresariais (verificar se existem)
            if ($schema->hasColumn('users', 'razao_social')) {
                $updateData['razao_social'] = $isGerente ? $this->generateRazaoSocial($user->name) : null;
            }
            if ($schema->hasColumn('users', 'nome_fantasia')) {
                $updateData['nome_fantasia'] = $isGerente ? $this->generateNomeFantasia($user->name) : null;
            }
            if ($schema->hasColumn('users', 'cartao_cnpj')) {
                $updateData['cartao_cnpj'] = $isGerente ? $this->randomCNPJ() : null;
            }
            
            // Webhooks (apenas para gerentes)
            if ($schema->hasColumn('users', 'webhook_url') && $isGerente) {
                $updateData['webhook_url'] = 'https://webhook.exemplo.com/' . $user->username;
            }
            if ($schema->hasColumn('users', 'webhook_endpoint') && $isGerente) {
                $updateData['webhook_endpoint'] = '/api/webhook/' . $user->username;
            }
            
            // Taxas Personalizadas (apenas para gerentes, ou valores padrão para usuários)
            if ($schema->hasColumn('users', 'taxas_personalizadas_ativas')) {
                $updateData['taxas_personalizadas_ativas'] = $isGerente;
            }
            if ($schema->hasColumn('users', 'taxa_percentual_deposito')) {
                // Se for gerente, usar valor aleatório, senão não atualizar (manter padrão do sistema)
                if ($isGerente) {
                    $updateData['taxa_percentual_deposito'] = rand(15, 35) / 10;
                }
            }
            if ($schema->hasColumn('users', 'taxa_fixa_deposito')) {
                // Se for gerente, usar valor aleatório, senão não atualizar
                if ($isGerente) {
                    $updateData['taxa_fixa_deposito'] = rand(100, 300) / 100;
                }
            }
            if ($schema->hasColumn('users', 'valor_minimo_deposito')) {
                // Se for gerente, usar valor aleatório, senão não atualizar
                if ($isGerente) {
                    $updateData['valor_minimo_deposito'] = rand(10, 50);
                }
            }
            
            if ($schema->hasColumn('users', 'taxa_percentual_pix')) {
                // Se for gerente, usar valor aleatório, senão não atualizar
                if ($isGerente) {
                    $updateData['taxa_percentual_pix'] = rand(20, 45) / 10;
                }
            }
            if ($schema->hasColumn('users', 'taxa_minima_pix')) {
                // Se for gerente, usar valor aleatório, senão não atualizar
                if ($isGerente) {
                    $updateData['taxa_minima_pix'] = rand(150, 350) / 100;
                }
            }
            if ($schema->hasColumn('users', 'taxa_fixa_pix')) {
                // Se for gerente, usar valor aleatório, senão não atualizar
                if ($isGerente) {
                    $updateData['taxa_fixa_pix'] = rand(100, 250) / 100;
                }
            }
            
            if ($schema->hasColumn('users', 'valor_minimo_saque')) {
                $updateData['valor_minimo_saque'] = rand(50, 100);
            }
            if ($schema->hasColumn('users', 'limite_mensal_pf')) {
                $updateData['limite_mensal_pf'] = rand(30000, 100000);
            }
            
            if ($schema->hasColumn('users', 'taxa_saque_api')) {
                // Se for gerente, usar valor aleatório, senão não atualizar
                if ($isGerente) {
                    $updateData['taxa_saque_api'] = rand(100, 300) / 100;
                }
            }
            if ($schema->hasColumn('users', 'taxa_saque_crypto')) {
                // Se for gerente, usar valor aleatório, senão não atualizar
                if ($isGerente) {
                    $updateData['taxa_saque_crypto'] = rand(15, 30) / 10;
                }
            }
            
            // Configurações de Cash In/Out
            if ($schema->hasColumn('users', 'taxa_cash_in')) {
                $updateData['taxa_cash_in'] = rand(25, 40) / 10;
            }
            if ($schema->hasColumn('users', 'taxa_cash_out')) {
                $updateData['taxa_cash_out'] = rand(20, 35) / 10;
            }
            if ($schema->hasColumn('users', 'taxa_cash_in_fixa')) {
                $updateData['taxa_cash_in_fixa'] = rand(150, 300) / 100;
            }
            if ($schema->hasColumn('users', 'taxa_cash_out_fixa')) {
                $updateData['taxa_cash_out_fixa'] = rand(100, 250) / 100;
            }
            
            // Afiliação e Comissões (apenas gerentes)
            if ($schema->hasColumn('users', 'is_affiliate')) {
                $updateData['is_affiliate'] = $isGerente;
            }
            if ($schema->hasColumn('users', 'affiliate_code') && $isGerente) {
                $updateData['affiliate_code'] = strtoupper($user->username) . '-AFF';
            }
            if ($schema->hasColumn('users', 'affiliate_link') && $isGerente) {
                $updateData['affiliate_link'] = 'https://orizon.com/ref/' . strtoupper($user->username);
            }
            if ($schema->hasColumn('users', 'affiliate_percentage') && $isGerente) {
                $updateData['affiliate_percentage'] = rand(5, 15) / 10;
            }
            
            // IP Whitelisting (apenas gerentes)
            if ($schema->hasColumn('users', 'whitelisted_ip') && $isGerente) {
                $updateData['whitelisted_ip'] = $this->generateWhitelistedIPs();
            }
            if ($schema->hasColumn('users', 'ips_saque_permitidos') && $isGerente) {
                $updateData['ips_saque_permitidos'] = $this->generateWhitelistedIPs();
            }
            
            // Configurações 2FA
            if ($schema->hasColumn('users', 'twofa_enabled')) {
                $updateData['twofa_enabled'] = rand(0, 1) ? true : false;
            }
            if ($schema->hasColumn('users', 'twofa_enabled_at')) {
                $updateData['twofa_enabled_at'] = rand(0, 1) ? now()->subDays(rand(1, 30)) : null;
            }
            
            // Dados de Faturamento
            if ($schema->hasColumn('users', 'media_faturamento')) {
                $updateData['media_faturamento'] = $this->randomFaturamento($isGerente);
            }
            
            // Área de Atuação
            if ($schema->hasColumn('users', 'area_atuacao')) {
                $updateData['area_atuacao'] = $this->randomAreaAtuacao();
            }
            
            // Status e Aprovações
            if ($schema->hasColumn('users', 'aprovado_alguma_vez')) {
                $updateData['aprovado_alguma_vez'] = true;
            }
            if ($schema->hasColumn('users', 'saque_bloqueado')) {
                $updateData['saque_bloqueado'] = false;
            }

            // Atualizar apenas se houver dados para atualizar
            if (!empty($updateData)) {
                $updateData['updated_at'] = now();
                DB::table('users')->where('id', $user->id)->update($updateData);
            }

            $this->command->info(
                sprintf(
                    "✅ Dados completos para: %s (%s) | %s",
                    $user->name,
                    $user->username,
                    $isGerente ? 'Gerente' : 'Usuário'
                )
            );
        }

        $this->command->info('');
        $this->command->info('Dados da conta atualizados com sucesso!');
    }

    /**
     * Gerar razão social
     */
    private function generateRazaoSocial(string $name): string
    {
        $sobrenomes = explode(' ', $name);
        $ultimo = end($sobrenomes);
        return strtoupper($ultimo) . ' COMERCIO E SERVICOS LTDA';
    }

    /**
     * Gerar nome fantasia
     */
    private function generateNomeFantasia(string $name): string
    {
        $primeiro = explode(' ', $name)[0];
        $sufixos = ['Store', 'Shop', 'Digital', 'Online', 'Express', 'Premium'];
        return $primeiro . ' ' . $sufixos[array_rand($sufixos)];
    }

    /**
     * Gerar CNPJ aleatório (formato)
     */
    private function randomCNPJ(): string
    {
        return sprintf(
            '%02d.%03d.%03d/%04d-%02d',
            rand(10, 99),
            rand(100, 999),
            rand(100, 999),
            rand(1000, 9999),
            rand(10, 99)
        );
    }

    /**
     * Gerar banco aleatório
     */
    private function randomBanco(): string
    {
        $bancos = [
            '001 - Banco do Brasil',
            '033 - Santander',
            '104 - Caixa Econômica',
            '237 - Bradesco',
            '341 - Itaú',
            '748 - Sicredi',
            '756 - Sicoob',
            '077 - Inter',
            '260 - Nu Pagamentos (Nubank)',
            '290 - PagSeguro',
        ];
        return $bancos[array_rand($bancos)];
    }

    /**
     * Gerar IPs whitelisted
     */
    private function generateWhitelistedIPs(): string
    {
        $ips = [];
        for ($i = 0; $i < rand(2, 5); $i++) {
            $ips[] = rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255);
        }
        return implode(',', $ips);
    }

    /**
     * Gerar média de faturamento
     */
    private function randomFaturamento(bool $isGerente): string
    {
        if ($isGerente) {
            $faixas = [
                'R$ 100.000 - R$ 500.000',
                'R$ 500.000 - R$ 1.000.000',
                'R$ 1.000.000 - R$ 5.000.000',
                'Acima de R$ 5.000.000',
            ];
        } else {
            $faixas = [
                'R$ 10.000 - R$ 50.000',
                'R$ 50.000 - R$ 100.000',
                'R$ 100.000 - R$ 500.000',
            ];
        }
        return $faixas[array_rand($faixas)];
    }

    /**
     * Gerar área de atuação
     */
    private function randomAreaAtuacao(): string
    {
        $areas = [
            'E-commerce',
            'Tecnologia',
            'Educação',
            'Saúde',
            'Consultoria',
            'Marketing Digital',
            'Vendas Online',
            'Serviços Financeiros',
            'Entretenimento',
            'Marketplace',
        ];
        return $areas[array_rand($areas)];
    }
}

