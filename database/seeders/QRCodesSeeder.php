<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class QRCodesSeeder extends Seeder
{
    /**
     * Criar QR Codes de teste (checkout_build)
     * 30 QR codes para testar filtros e paginação
     */
    public function run(): void
    {
        // Buscar IDs de usuários criados
        $userIds = DB::table('users')->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])->pluck('user_id')->toArray();
        
        if (empty($userIds)) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        $qrCodes = [];
        $statuses = [true, true, true, false]; // Mais ativos que inativos
        $cobrancaTipos = ['PIX', 'BOLETO', 'CREDIT_CARD'];
        
        for ($i = 1; $i <= 30; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $valor = $this->randomAmount(10, 5000);
            $deValor = $valor * 1.5; // Valor "de" maior que o valor atual
            $status = $statuses[array_rand($statuses)];
            $daysAgo = rand(0, 60);
            $date = Carbon::now()->subDays($daysAgo);

            $produtoNames = [
                'Curso de Marketing Digital',
                'E-book de Vendas Online',
                'Consultoria Empresarial',
                'Mentoria de Negócios',
                'Treinamento em TI',
                'Workshop de Inovação',
                'Plano Premium',
                'Assinatura Mensal',
                'Produto Digital',
                'Serviço de Consultoria',
                'Curso Avançado',
                'Pacote Completo',
                'Licença Anual',
                'Acesso VIP',
                'Programa de Capacitação'
            ];

            // Usar estrutura básica da tabela checkout_build (campos da migration)
            $qrCode = [
                'user_id' => $userId,
                'name_produto' => $produtoNames[array_rand($produtoNames)] . ' #' . $i,
                'valor' => (string) $valor,
                'referencia' => 'QR-' . strtoupper(uniqid()),
                'logo_produto' => null,
                'obrigado_page' => 'https://orizon.com/obrigado/' . uniqid(),
                'key_gateway' => 'pix',
                'ativo' => $status,
                'email' => 'suporte' . $i . '@orizon.com',
                'url_checkout' => 'https://orizon.com/checkout/' . uniqid(),
                'banner_produto' => null,
                'created_at' => $date,
                'updated_at' => $date,
            ];
            
            // Adicionar campos extras se existirem na tabela
            $schema = DB::getSchemaBuilder();
            if ($schema->hasColumn('checkout_build', 'produto_descricao')) {
                $qrCode['produto_descricao'] = 'Descrição completa do produto digital com detalhes sobre o conteúdo e benefícios oferecidos.';
            }
            if ($schema->hasColumn('checkout_build', 'produto_valor')) {
                $qrCode['produto_valor'] = $valor;
            }
            if ($schema->hasColumn('checkout_build', 'produto_tipo_cob')) {
                $qrCode['produto_tipo_cob'] = $cobrancaTipos[array_rand($cobrancaTipos)];
            }
            if ($schema->hasColumn('checkout_build', 'status')) {
                $qrCode['status'] = $status;
            }
            
            $qrCodes[] = $qrCode;
        }

        // Limpar QR codes de seed anteriores (usar name_produto)
        // Verificar se a coluna existe antes de limpar
        $schema = DB::getSchemaBuilder();
        if ($schema->hasTable('checkout_build')) {
            if ($schema->hasColumn('checkout_build', 'name_produto')) {
                DB::table('checkout_build')
                    ->where('name_produto', 'like', '% #%')
                    ->delete();
            }
        }
        
        // Inserir em lotes
        DB::table('checkout_build')->insert($qrCodes);
        $this->command->info('30 QR codes criados.');
    }

    /**
     * Gerar valor aleatório
     */
    private function randomAmount(float $min, float $max): float
    {
        return round(mt_rand($min * 100, $max * 100) / 100, 2);
    }

    /**
     * Gerar categoria aleatória
     */
    private function randomCategory(): string
    {
        $categories = [
            'Educação',
            'Tecnologia',
            'Marketing',
            'Vendas',
            'Consultoria',
            'Treinamento',
            'Assinatura',
            'E-commerce',
            'Serviços',
            'Digital'
        ];
        return $categories[array_rand($categories)];
    }

    /**
     * Gerar cor aleatória
     */
    private function randomColor(): string
    {
        $colors = [
            '#007BFF', // Azul
            '#28A745', // Verde
            '#DC3545', // Vermelho
            '#FFC107', // Amarelo
            '#17A2B8', // Ciano
            '#6F42C1', // Roxo
            '#E83E8C', // Rosa
            '#FD7E14', // Laranja
        ];
        return $colors[array_rand($colors)];
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

