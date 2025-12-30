<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TransactionsSeeder extends Seeder
{
    /**
     * Criar transações de teste: depósitos e saques
     * 30 depósitos e 30 saques para testar filtros e paginação
     */
    public function run(): void
    {
        $this->createDeposits();
        $this->createWithdrawals();
        $this->command->info('Transações criadas com sucesso!');
    }

    /**
     * Criar 30 depósitos (solicitacoes)
     */
    private function createDeposits(): void
    {
        // Buscar IDs de usuários criados
        $userIds = DB::table('users')->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])->pluck('user_id')->toArray();
        
        if (empty($userIds)) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        $deposits = [];
        $statuses = ['COMPLETED', 'COMPLETED', 'COMPLETED', 'PENDING', 'CANCELLED']; // Mais completos que pendentes
        
        for ($i = 1; $i <= 30; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $amount = $this->randomAmount(50, 15000);
            $taxaPercentual = 3.5;
            $taxaFixa = 2.50;
            $taxaTotal = ($amount * $taxaPercentual / 100) + $taxaFixa;
            $depositoLiquido = $amount - $taxaTotal;
            $status = $statuses[array_rand($statuses)];
            $daysAgo = rand(0, 30);
            $date = Carbon::now()->subDays($daysAgo);

            // idTransaction é obrigatório (não pode ser null)
            $idTransaction = 'SEED-DEP-' . str_pad($i, 4, '0', STR_PAD_LEFT);
            $externalReference = 'EXT-' . strtoupper(uniqid());

            $deposits[] = [
                'user_id' => $userId,
                'externalreference' => $externalReference,
                'amount' => $amount,
                'client_name' => $this->randomName(),
                'client_document' => $this->randomCPF(),
                'client_email' => 'cliente' . $i . '@exemplo.com',
                'client_telefone' => $this->randomPhone(),
                'date' => $date,
                'status' => $status,
                'idTransaction' => $idTransaction, // Sempre gerar ID, mesmo para PENDING/CANCELLED
                'deposito_liquido' => $depositoLiquido,
                'taxa_cash_in' => $taxaPercentual,
                'taxa_pix_cash_in_adquirente' => 1.5,
                'taxa_pix_cash_in_valor_fixo' => $taxaFixa,
                'qrcode_pix' => '00020126580014br.gov.bcb.pix0136' . uniqid() . '52040000530398654' . number_format($amount, 2, '', ''),
                'paymentcode' => $idTransaction,
                'paymentCodeBase64' => base64_encode($idTransaction),
                'adquirente_ref' => $this->randomAdquirente(),
                'executor_ordem' => $userId,
                'descricao_transacao' => 'WEB',
                'callback' => null,
                'split_email' => null,
                'split_percentage' => null,
                'created_at' => $date,
                'updated_at' => $date,
            ];
        }

        // Limpar depósitos de seed anteriores
        DB::table('solicitacoes')->where('externalreference', 'like', 'EXT-%')->delete();
        
        // Inserir em lotes
        DB::table('solicitacoes')->insert($deposits);
        $this->command->info('30 depósitos criados.');
    }

    /**
     * Criar 30 saques (solicitacoes_cash_out)
     */
    private function createWithdrawals(): void
    {
        // Buscar IDs de usuários criados
        $userIds = DB::table('users')->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])->pluck('user_id')->toArray();
        
        if (empty($userIds)) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        $withdrawals = [];
        $statuses = ['COMPLETED', 'COMPLETED', 'PENDING', 'PENDING', 'CANCELLED'];
        $types = ['cpf', 'cnpj', 'email', 'phone', 'random'];
        
        for ($i = 1; $i <= 30; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $amount = $this->randomAmount(100, 8000);
            $taxaCashOut = $amount * 0.02; // 2%
            $cashOutLiquido = $amount - $taxaCashOut;
            $status = $statuses[array_rand($statuses)];
            $daysAgo = rand(0, 30);
            $date = Carbon::now()->subDays($daysAgo);
            $type = $types[array_rand($types)];

            $idTransaction = $status === 'COMPLETED' ? 'SEED-WD-' . str_pad($i, 4, '0', STR_PAD_LEFT) : null;
            $externalReference = 'WD-EXT-' . strtoupper(uniqid());

            $pixData = $this->generatePixData($type);

            $withdrawals[] = [
                'user_id' => $userId,
                'externalreference' => $externalReference,
                'amount' => $amount,
                'beneficiaryname' => $this->randomName(),
                'beneficiarydocument' => $this->randomCPF(),
                'pix' => $pixData['pix'],
                'pixkey' => $pixData['pixkey'],
                'date' => $date,
                'status' => $status,
                'type' => strtoupper($pixData['type']),
                'idTransaction' => $idTransaction,
                'taxa_cash_out' => $taxaCashOut,
                'cash_out_liquido' => $cashOutLiquido,
                'descricao_transacao' => 'WEB',
                'executor_ordem' => $status === 'COMPLETED' && rand(0, 1) ? 'AUTO:SYSTEM' : null,
                'created_at' => $date,
                'updated_at' => $date,
            ];
        }

        // Limpar saques de seed anteriores
        DB::table('solicitacoes_cash_out')->where('externalreference', 'like', 'WD-EXT-%')->delete();
        
        // Inserir em lotes
        DB::table('solicitacoes_cash_out')->insert($withdrawals);
        $this->command->info('30 saques criados.');
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

    /**
     * Selecionar adquirente aleatório
     */
    private function randomAdquirente(): string
    {
        $adquirentes = ['cashtime', 'efi', 'pagarme', 'mercadopago', 'woovi', 'primepay7'];
        return $adquirentes[array_rand($adquirentes)];
    }
}

