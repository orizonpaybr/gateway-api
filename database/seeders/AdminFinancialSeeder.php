<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminFinancialSeeder extends Seeder
{
    /**
     * Popular dados para as seções do Financeiro
     * Transações, Carteiras, Entradas e Saídas
     */
    public function run(): void
    {
        // Buscar usuários criados
        $userIds = DB::table('users')
            ->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])
            ->pluck('user_id')
            ->toArray();
        
        if (empty($userIds)) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        // 1. Criar transações financeiras (para Financeiro > Transações)
        $this->createFinancialTransactions($userIds);
        
        // 2. Atualizar dados de carteiras (para Financeiro > Carteiras)
        $this->updateWalletsData($userIds);
        
        // 3. Criar depósitos para relatório de entradas (para Financeiro > Entradas)
        $this->createEntriesReport($userIds);
        
        // 4. Criar saques para relatório de saídas (para Financeiro > Saídas)
        $this->createExitsReport($userIds);
        
        $this->command->info('Dados do Financeiro populados com sucesso!');
    }

    /**
     * Criar transações financeiras para a listagem
     */
    private function createFinancialTransactions(array $userIds): void
    {
        // Buscar depósitos e saques existentes
        $deposits = DB::table('solicitacoes')
            ->where('status', 'COMPLETED')
            ->whereNotNull('idTransaction')
            ->limit(50)
            ->get();

        $withdrawals = DB::table('solicitacoes_cash_out')
            ->where('status', 'COMPLETED')
            ->whereNotNull('idTransaction')
            ->limit(50)
            ->get();

        $this->command->info("Transações financeiras: {$deposits->count()} depósitos e {$withdrawals->count()} saques disponíveis.");
    }

    /**
     * Atualizar dados de carteiras para o relatório
     */
    private function updateWalletsData(array $userIds): void
    {
        $faturamentos = [
            'gerente1' => 8500000.00,
            'gerente2' => 12500000.00,
            'usuario1' => 3200000.00,
            'usuario2' => 5800000.00,
        ];

        foreach ($userIds as $userId) {
            $user = DB::table('users')->where('user_id', $userId)->first();
            if (!$user) continue;

            $faturamento = $faturamentos[$user->username] ?? 2000000.00;
            
            // Calcular faturamento baseado nas transações
            $faturamentoReal = DB::table('solicitacoes')
                ->where('user_id', $userId)
                ->where('status', 'COMPLETED')
                ->sum('amount');

            // Usar o maior valor
            $faturamentoFinal = max($faturamento, $faturamentoReal);

            DB::table('users')->where('user_id', $userId)->update([
                'volume_transacional' => $faturamentoFinal,
                'updated_at' => now(),
            ]);
        }

        $this->command->info('Dados de carteiras atualizados.');
    }

    /**
     * Criar depósitos para relatório de entradas
     */
    private function createEntriesReport(array $userIds): void
    {
        $deposits = [];
        $statuses = ['COMPLETED', 'COMPLETED', 'COMPLETED', 'PENDING', 'CANCELLED'];
        
        for ($i = 1; $i <= 40; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $amount = $this->randomAmount(1000, 50000); // R$ 1k - R$ 50k
            $taxaPercentual = 3.0;
            $taxaFixa = 15.00;
            $taxaTotal = ($amount * $taxaPercentual / 100) + $taxaFixa;
            $depositoLiquido = $amount - $taxaTotal;
            $status = $statuses[array_rand($statuses)];
            $daysAgo = rand(0, 90);
            $date = Carbon::now()->subDays($daysAgo);

            // idTransaction é obrigatório (não pode ser null)
            $idTransaction = 'FIN-ENT-' . str_pad($i, 4, '0', STR_PAD_LEFT);
            $externalReference = 'FIN-ENT-EXT-' . strtoupper(uniqid());

            $deposits[] = [
                'user_id' => $userId,
                'externalreference' => $externalReference,
                'amount' => $amount,
                'client_name' => 'Cliente Entrada ' . $i,
                'client_document' => sprintf('%03d.%03d.%03d-%02d', rand(100, 999), rand(100, 999), rand(100, 999), rand(10, 99)),
                'client_email' => 'entrada' . $i . '@exemplo.com',
                'client_telefone' => '(' . rand(11, 99) . ') 9' . rand(1000, 9999) . '-' . rand(1000, 9999),
                'date' => $date,
                'status' => $status,
                'idTransaction' => $idTransaction,
                'deposito_liquido' => $depositoLiquido,
                'taxa_cash_in' => $taxaPercentual,
                'taxa_pix_cash_in_adquirente' => 1.5,
                'taxa_pix_cash_in_valor_fixo' => $taxaFixa,
                'qrcode_pix' => '00020126580014br.gov.bcb.pix0136' . uniqid(),
                'paymentcode' => $idTransaction ?? uniqid(),
                'paymentCodeBase64' => base64_encode($idTransaction ?? uniqid()),
                'adquirente_ref' => 'cashtime',
                'executor_ordem' => $userId,
                'descricao_transacao' => 'WEB',
                'created_at' => $date,
                'updated_at' => $date,
            ];
        }

        // Limpar depósitos financeiro anteriores
        DB::table('solicitacoes')->where('externalreference', 'like', 'FIN-ENT-EXT-%')->delete();
        
        // Inserir depósitos
        if (!empty($deposits)) {
            DB::table('solicitacoes')->insert($deposits);
            $this->command->info('40 depósitos criados para relatório de entradas.');
        }
    }

    /**
     * Criar saques para relatório de saídas
     */
    private function createExitsReport(array $userIds): void
    {
        $withdrawals = [];
        $statuses = ['COMPLETED', 'COMPLETED', 'PENDING', 'PENDING', 'CANCELLED'];
        $types = ['cpf', 'cnpj', 'email', 'phone', 'random'];
        
        for ($i = 1; $i <= 40; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $amount = $this->randomAmount(500, 20000); // R$ 500 - R$ 20k
            $taxaCashOut = $amount * 0.025; // 2.5%
            $cashOutLiquido = $amount - $taxaCashOut;
            $status = $statuses[array_rand($statuses)];
            $daysAgo = rand(0, 90);
            $date = Carbon::now()->subDays($daysAgo);
            $type = $types[array_rand($types)];

            $idTransaction = $status === 'COMPLETED' ? 'FIN-SAI-' . str_pad($i, 4, '0', STR_PAD_LEFT) : null;
            $externalReference = 'FIN-SAI-EXT-' . strtoupper(uniqid());

            $executorOrdem = ($status === 'COMPLETED' && rand(0, 1)) ? 'AUTO:SYSTEM' : null;
            $pixData = $this->generatePixData($type);

            $withdrawals[] = [
                'user_id' => $userId,
                'externalreference' => $externalReference,
                'amount' => $amount,
                'beneficiaryname' => $this->randomName(),
                'beneficiarydocument' => rand(0, 1) ? $this->randomCPF() : $this->randomCNPJ(),
                'pix' => $pixData['pix'],
                'pixkey' => $pixData['pixkey'],
                'date' => $date,
                'status' => $status,
                'type' => strtoupper($pixData['type']),
                'idTransaction' => $idTransaction,
                'taxa_cash_out' => $taxaCashOut,
                'cash_out_liquido' => $cashOutLiquido,
                'descricao_transacao' => 'WEB',
                'executor_ordem' => $executorOrdem,
                'created_at' => $date,
                'updated_at' => $date,
            ];
        }

        // Limpar saques financeiro anteriores
        DB::table('solicitacoes_cash_out')->where('externalreference', 'like', 'FIN-SAI-EXT-%')->delete();
        
        // Inserir saques
        if (!empty($withdrawals)) {
            DB::table('solicitacoes_cash_out')->insert($withdrawals);
            $this->command->info('40 saques criados para relatório de saídas.');
        }
    }

    /**
     * Gerar dados de PIX baseado no tipo
     */
    private function generatePixData(string $type): array
    {
        switch ($type) {
            case 'cpf':
                $cpf = $this->randomCPF();
                return ['pix' => 'cpf', 'pixkey' => $cpf, 'type' => 'CPF'];
            case 'cnpj':
                $cnpj = $this->randomCNPJ();
                return ['pix' => 'cnpj', 'pixkey' => $cnpj, 'type' => 'CNPJ'];
            case 'email':
                $email = 'pix' . rand(1000, 9999) . '@exemplo.com';
                return ['pix' => 'email', 'pixkey' => $email, 'type' => 'EMAIL'];
            case 'phone':
                $phone = $this->randomPhone();
                return ['pix' => 'phone', 'pixkey' => $phone, 'type' => 'PHONE'];
            default:
                $random = strtoupper(uniqid());
                return ['pix' => 'random', 'pixkey' => $random, 'type' => 'RANDOM'];
        }
    }

    /**
     * Gerar valor aleatório
     */
    private function randomAmount(float $min, float $max): float
    {
        return round(mt_rand($min * 100, $max * 100) / 100, 2);
    }

    /**
     * Gerar nome aleatório
     */
    private function randomName(): string
    {
        $names = [
            'João Silva', 'Maria Santos', 'Pedro Oliveira', 'Ana Costa', 'Carlos Souza',
            'Juliana Lima', 'Fernando Alves', 'Patrícia Rocha', 'Ricardo Martins', 'Fernanda Dias',
            'Lucas Pereira', 'Camila Rodrigues', 'Rafael Nascimento', 'Amanda Ferreira', 'Bruno Ribeiro',
            'Larissa Cardoso', 'Thiago Barbosa', 'Gabriela Mendes', 'Rodrigo Araújo', 'Mariana Castro',
            'Felipe Gomes', 'Aline Freitas', 'Marcos Monteiro', 'Renata Pinto', 'Eduardo Vieira',
            'Priscila Nunes', 'Daniel Correia', 'Tatiane Carvalho', 'Vinicius Ramos', 'Beatriz Teixeira'
        ];
        return $names[array_rand($names)];
    }

    /**
     * Gerar CPF aleatório (formato)
     */
    private function randomCPF(): string
    {
        return sprintf('%03d.%03d.%03d-%02d', rand(100, 999), rand(100, 999), rand(100, 999), rand(10, 99));
    }

    /**
     * Gerar CNPJ aleatório (formato)
     */
    private function randomCNPJ(): string
    {
        return sprintf('%02d.%03d.%03d/%04d-%02d', rand(10, 99), rand(100, 999), rand(100, 999), rand(1000, 9999), rand(10, 99));
    }

    /**
     * Gerar telefone aleatório (formato)
     */
    private function randomPhone(): string
    {
        $ddd = rand(11, 99);
        $number = rand(90000, 99999) . '-' . rand(1000, 9999);
        return "($ddd) $number";
    }
}

