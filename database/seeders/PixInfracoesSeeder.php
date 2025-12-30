<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PixInfracoesSeeder extends Seeder
{
    /**
     * Criar infrações PIX de teste
     * 30 infrações para testar filtros e paginação
     */
    public function run(): void
    {
        // Buscar IDs de usuários criados
        $userIds = DB::table('users')
            ->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])
            ->pluck('user_id')
            ->toArray();
        
        if (empty($userIds)) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        // Buscar algumas transações para vincular
        $transactionIds = DB::table('solicitacoes')
            ->where('status', 'COMPLETED')
            ->pluck('idTransaction')
            ->toArray();

        $infracoes = [];
        $statuses = ['PENDENTE', 'EM_ANALISE', 'RESOLVIDA', 'CANCELADA', 'CHARGEBACK', 'MEDIATION', 'DISPUTE'];
        $tipos = ['pix', 'chargeback', 'fraude', 'disputa', 'devolucao'];
        
        $descricoes = [
            'Solicitação de devolução do pagamento PIX',
            'Contestação de transação não reconhecida',
            'Pagamento duplicado identificado',
            'Chave PIX incorreta informada',
            'Valor divergente do combinado',
            'Serviço não prestado conforme acordado',
            'Produto não entregue',
            'Produto com defeito ou diferente do anunciado',
            'Cobrança indevida',
            'Cancelamento de compra solicitado',
            'Reversão por fraude confirmada',
            'Disputa de valor',
            'Erro no processamento do PIX',
            'Beneficiário não localizado',
            'Reclamação de qualidade do serviço'
        ];

        for ($i = 1; $i <= 30; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $status = $statuses[array_rand($statuses)];
            $tipo = $tipos[array_rand($tipos)];
            $valor = $this->randomAmount(50, 5000);
            $daysAgo = rand(0, 60);
            $dataCriacao = Carbon::now()->subDays($daysAgo);
            
            // Data limite: 30 dias após criação para pendentes/em análise
            $dataLimite = in_array($status, ['PENDENTE', 'EM_ANALISE']) 
                ? $dataCriacao->copy()->addDays(30) 
                : $dataCriacao->copy()->addDays(rand(15, 45));

            $transactionId = !empty($transactionIds) && rand(0, 1) 
                ? $transactionIds[array_rand($transactionIds)] 
                : null;

            $endToEnd = 'E' . date('Ymd') . uniqid();
            $descricao = $descricoes[array_rand($descricoes)];

            $detalhes = json_encode([
                'origem' => rand(0, 1) ? 'cliente' : 'sistema',
                'protocolo' => 'INF-' . strtoupper(uniqid()),
                'canal' => ['app', 'web', 'email', 'telefone'][rand(0, 3)],
                'prioridade' => ['baixa', 'media', 'alta'][rand(0, 2)],
                'observacoes' => 'Infração registrada automaticamente pelo sistema'
            ]);

            $infracoes[] = [
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'status' => $status,
                'tipo' => $tipo,
                'descricao' => $descricao,
                'descricao_normalizada' => strtolower($this->removeAccents($descricao)),
                'valor' => $valor,
                'end_to_end' => $endToEnd,
                'data_criacao' => $dataCriacao,
                'data_limite' => $dataLimite,
                'detalhes' => $detalhes,
                'created_at' => $dataCriacao,
                'updated_at' => now(),
            ];
        }

        // Limpar infrações de seed anteriores
        DB::table('pix_infracoes')->where('detalhes', 'like', '%INF-%')->delete();
        
        // Inserir em lotes
        DB::table('pix_infracoes')->insert($infracoes);
        $this->command->info('30 infrações PIX criadas.');

        // Criar infrações específicas para usuario1 (João da Silva Pereira)
        $this->createInfracoesForUsuario1();
    }

    /**
     * Criar infrações específicas para usuario1 (João da Silva Pereira)
     */
    private function createInfracoesForUsuario1(): void
    {
        // Buscar user_id do usuario1
        $usuario1 = DB::table('users')
            ->where('username', 'usuario1')
            ->where('email', 'usuario1@exemplo.com')
            ->first();

        if (!$usuario1) {
            $this->command->warn('Usuário usuario1 não encontrado. Pulando criação de infrações específicas.');
            return;
        }

        // Buscar algumas transações do usuario1 para vincular
        $transactionIds = DB::table('solicitacoes')
            ->where('user_id', $usuario1->user_id)
            ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->pluck('idTransaction')
            ->toArray();

        $infracoesUsuario1 = [];
        $statuses = ['PENDENTE', 'EM_ANALISE', 'RESOLVIDA', 'CANCELADA', 'CHARGEBACK', 'MEDIATION', 'DISPUTE'];
        
        // Criar 10 infrações específicas para usuario1
        $descricoesUsuario1 = [
            'Solicitação de devolução do pagamento PIX - Cliente não reconhece transação',
            'Contestação de transação não autorizada',
            'Pagamento duplicado identificado no sistema',
            'Chave PIX incorreta informada pelo cliente',
            'Valor divergente do combinado na transação',
            'Serviço não prestado conforme acordado',
            'Produto não entregue no prazo estipulado',
            'Produto com defeito ou diferente do anunciado',
            'Cobrança indevida identificada',
            'Cancelamento de compra solicitado pelo cliente'
        ];

        for ($i = 1; $i <= 10; $i++) {
            $status = $statuses[array_rand($statuses)];
            $valor = $this->randomAmount(100, 3000);
            $daysAgo = rand(0, 45); // Últimos 45 dias
            $dataCriacao = Carbon::now()->subDays($daysAgo);
            
            // Data limite: 30 dias após criação para pendentes/em análise
            $dataLimite = in_array($status, ['PENDENTE', 'EM_ANALISE']) 
                ? $dataCriacao->copy()->addDays(30) 
                : $dataCriacao->copy()->addDays(rand(15, 45));

            $transactionId = !empty($transactionIds) && rand(0, 1) 
                ? $transactionIds[array_rand($transactionIds)] 
                : null;

            $endToEnd = 'E' . date('Ymd') . strtoupper(uniqid());
            $descricao = $descricoesUsuario1[$i - 1];

            $detalhes = json_encode([
                'origem' => rand(0, 1) ? 'cliente' : 'sistema',
                'protocolo' => 'INF-USUARIO1-' . strtoupper(uniqid()),
                'canal' => ['app', 'web', 'email', 'telefone'][rand(0, 3)],
                'prioridade' => ['baixa', 'media', 'alta'][rand(0, 2)],
                'observacoes' => 'Infração registrada para validação - Usuário: João da Silva Pereira',
                'usuario' => 'usuario1',
                'email' => 'usuario1@exemplo.com'
            ]);

            $infracoesUsuario1[] = [
                'user_id' => $usuario1->user_id,
                'transaction_id' => $transactionId,
                'status' => $status,
                'tipo' => 'pix',
                'descricao' => $descricao,
                'descricao_normalizada' => strtolower($this->removeAccents($descricao)),
                'valor' => $valor,
                'end_to_end' => $endToEnd,
                'data_criacao' => $dataCriacao,
                'data_limite' => $dataLimite,
                'detalhes' => $detalhes,
                'created_at' => $dataCriacao,
                'updated_at' => now(),
            ];
        }

        // Inserir infrações do usuario1
        DB::table('pix_infracoes')->insert($infracoesUsuario1);
        $this->command->info('10 infrações PIX criadas para usuario1 (João da Silva Pereira).');
    }

    /**
     * Gerar valor aleatório
     */
    private function randomAmount(float $min, float $max): float
    {
        return round(mt_rand($min * 100, $max * 100) / 100, 2);
    }

    /**
     * Remover acentos de string
     */
    private function removeAccents(string $string): string
    {
        $unwanted = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ç' => 'c', 'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'É' => 'E',
            'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'Ç' => 'C'
        ];
        return strtr($string, $unwanted);
    }
}




