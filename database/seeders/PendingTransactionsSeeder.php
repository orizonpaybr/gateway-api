<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PendingTransactionsSeeder extends Seeder
{
    /**
     * Criar transações pendentes de teste
     * 30 transações pendentes para testar a tela de Transações Pendentes
     */
    public function run(): void
    {
        // Buscar IDs de usuários criados
        $userIds = DB::table('users')->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])->pluck('user_id')->toArray();
        
        if (empty($userIds)) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        $pendingTransactions = [];
        $types = ['cpf', 'cnpj', 'email', 'phone', 'random'];
        
        for ($i = 1; $i <= 30; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $amount = $this->randomAmount(50, 10000);
            $taxaCashOut = $amount * 0.025; // 2.5%
            $cashOutLiquido = $amount - $taxaCashOut;
            $daysAgo = rand(0, 15); // Pendentes recentes
            $hoursAgo = rand(0, 23);
            $minutesAgo = rand(0, 59);
            $date = Carbon::now()->subDays($daysAgo)->subHours($hoursAgo)->subMinutes($minutesAgo);
            $type = $types[array_rand($types)];

            $externalReference = 'PEND-' . strtoupper(uniqid());
            $pixData = $this->generatePixData($type);

            $pendingTransactions[] = [
                'user_id' => $userId,
                'externalreference' => $externalReference,
                'amount' => $amount,
                'beneficiaryname' => $this->randomName(),
                'beneficiarydocument' => rand(0, 1) ? $this->randomCPF() : $this->randomCNPJ(),
                'pix' => $pixData['pix'],
                'pixkey' => $pixData['pixkey'],
                'date' => $date,
                'status' => 'PENDING',
                'type' => strtoupper($pixData['type']),
                'idTransaction' => null, // Pendentes não têm ID de transação
                'taxa_cash_out' => $taxaCashOut,
                'cash_out_liquido' => $cashOutLiquido,
                'descricao_transacao' => 'WEB',
                'executor_ordem' => null, // Pendentes ainda não foram executados
                'created_at' => $date,
                'updated_at' => $date,
            ];
        }

        // Limpar transações pendentes de seed anteriores
        DB::table('solicitacoes_cash_out')
            ->where('status', 'PENDING')
            ->where('externalreference', 'like', 'PEND-%')
            ->delete();
        
        // Inserir em lotes
        DB::table('solicitacoes_cash_out')->insert($pendingTransactions);
        $this->command->info('30 transações pendentes criadas.');
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
            'Priscila Nunes', 'Daniel Correia', 'Tatiane Carvalho', 'Vinicius Ramos', 'Beatriz Teixeira',
            'Alexandre Cunha', 'Isabela Moura', 'Gustavo Lopes', 'Carolina Pires', 'Fábio Azevedo'
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





