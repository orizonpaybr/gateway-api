<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardDataSeeder extends Seeder
{
    /**
     * Popular dados para o Dashboard com valores acima de 1 milhão
     * Para testar a responsividade dos cards e formatação de números grandes
     */
    public function run(): void
    {
        // Atualizar configurações do app
        $this->updateAppConfig();
        
        // Atualizar dados dos usuários com valores altos
        $this->updateUsersDashboardData();
        
        $this->command->info('Dados do dashboard atualizados com valores acima de 1 milhão.');
    }

    /**
     * Atualizar configurações da aplicação
     */
    private function updateAppConfig(): void
    {
        $appExists = DB::table('app')->where('id', 1)->exists();
        
        if (!$appExists) {
            // Criar registro de configuração se não existir
            DB::table('app')->insert([
                'id' => 1,
                'saque_automatico' => true,
                'limite_saque_automatico' => 5000.00,
                'niveis_ativo' => true,
                'deposito_minimo' => 10.00,
                'saque_minimo' => 50.00,
                'taxa_fixa_padrao_cash_out' => 2.50,
                'limite_saque_mensal' => 50000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // Atualizar configurações existentes
            DB::table('app')->where('id', 1)->update([
                'saque_automatico' => true,
                'limite_saque_automatico' => 5000.00,
                'niveis_ativo' => true,
                'updated_at' => now(),
            ]);
        }

        $this->command->info('Configurações da aplicação atualizadas.');
    }

    /**
     * Atualizar dados dos usuários para o dashboard
     */
    private function updateUsersDashboardData(): void
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
            // Calcular valores baseados nas transações do usuário
            $depositosCompletos = DB::table('solicitacoes')
                ->where('user_id', $user->user_id)
                ->where('status', 'COMPLETED')
                ->sum('amount');

            $saquesCompletos = DB::table('solicitacoes_cash_out')
                ->where('user_id', $user->user_id)
                ->where('status', 'COMPLETED')
                ->sum('amount');

            $saquesPendentes = DB::table('solicitacoes_cash_out')
                ->where('user_id', $user->user_id)
                ->where('status', 'PENDING')
                ->sum('amount');

            $totalTransacoes = DB::table('solicitacoes')
                ->where('user_id', $user->user_id)
                ->count();

            $transacoesAprovadas = DB::table('solicitacoes')
                ->where('user_id', $user->user_id)
                ->where('status', 'COMPLETED')
                ->count();

            $transacoesRecusadas = DB::table('solicitacoes')
                ->where('user_id', $user->user_id)
                ->where('status', 'CANCELLED')
                ->count();

            // Calcular saldo: manter o saldo alto existente + adicionar novo volume
            $volumeTransacional = $depositosCompletos + $saquesCompletos;
            $saldoAtual = max($user->saldo, 1500000.00); // Garantir mínimo de 1.5M
            
            // Atualizar usuário
            DB::table('users')->where('id', $user->id)->update([
                'saldo' => $saldoAtual + ($depositosCompletos * 0.1), // Aumentar saldo
                'volume_transacional' => $volumeTransacional + 2000000, // Adicionar volume base
                'total_transacoes' => max($totalTransacoes, 100),
                'transacoes_aproved' => max($transacoesAprovadas, 90),
                'transacoes_recused' => max($transacoesRecusadas, 10),
                'valor_sacado' => $saquesCompletos + 500000, // Adicionar valor base
                'valor_saque_pendente' => $saquesPendentes,
                'updated_at' => now(),
            ]);
        }

        // Criar transações adicionais para aumentar os valores do dashboard geral
        $this->createHighValueTransactions();

        $this->command->info('Dados dos usuários atualizados para o dashboard.');
    }

    /**
     * Criar transações de alto valor para popular o dashboard
     */
    private function createHighValueTransactions(): void
    {
        $userIds = DB::table('users')
            ->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])
            ->pluck('user_id')
            ->toArray();

        if (empty($userIds)) {
            return;
        }

        // Criar depósitos de alto valor
        $highValueDeposits = [];
        for ($i = 1; $i <= 20; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $amount = rand(50000, 500000); // Entre 50k e 500k
            $taxaPercentual = 2.5;
            $taxaFixa = 15.00;
            $taxaTotal = ($amount * $taxaPercentual / 100) + $taxaFixa;
            $depositoLiquido = $amount - $taxaTotal;
            $daysAgo = rand(0, 30);
            $date = Carbon::now()->subDays($daysAgo);

            $idTransaction = 'DASH-DEP-' . str_pad($i, 4, '0', STR_PAD_LEFT);

            $highValueDeposits[] = [
                'user_id' => $userId,
                'externalreference' => 'DASH-EXT-' . strtoupper(uniqid()),
                'amount' => $amount,
                'client_name' => 'Cliente Alto Valor ' . $i,
                'client_document' => sprintf('%03d.%03d.%03d-%02d', rand(100, 999), rand(100, 999), rand(100, 999), rand(10, 99)),
                'client_email' => 'altovalor' . $i . '@exemplo.com',
                'client_telefone' => '(11) 9' . rand(1000, 9999) . '-' . rand(1000, 9999),
                'date' => $date,
                'status' => 'COMPLETED',
                'idTransaction' => $idTransaction,
                'deposito_liquido' => $depositoLiquido,
                'taxa_cash_in' => $taxaPercentual,
                'taxa_pix_cash_in_adquirente' => 1.5,
                'taxa_pix_cash_in_valor_fixo' => $taxaFixa,
                'qrcode_pix' => '00020126580014br.gov.bcb.pix0136' . uniqid(),
                'paymentcode' => $idTransaction,
                'paymentCodeBase64' => base64_encode($idTransaction),
                'adquirente_ref' => 'cashtime',
                'executor_ordem' => $userId,
                'descricao_transacao' => 'WEB',
                'created_at' => $date,
                'updated_at' => $date,
            ];
        }

        // Limpar depósitos de alto valor anteriores
        DB::table('solicitacoes')->where('externalreference', 'like', 'DASH-EXT-%')->delete();
        
        // Inserir depósitos de alto valor
        if (!empty($highValueDeposits)) {
            DB::table('solicitacoes')->insert($highValueDeposits);
            $this->command->info('20 depósitos de alto valor criados para o dashboard.');
        }
    }
}





