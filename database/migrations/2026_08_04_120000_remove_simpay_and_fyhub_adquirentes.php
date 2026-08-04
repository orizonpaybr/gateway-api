<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Depreciação SIMPAY e FYHUB: código das integrações removido do projeto.
 *
 * Não toca em `solicitacoes` / `solicitacoes_cash_out` — as transações históricas
 * mantêm executor_ordem = 'simpay' / 'fyhub' e continuam sendo custeadas pelas
 * constantes em {@see \App\Helpers\CustoAdquirentePixHelper}.
 */
return new class extends Migration
{
    private const REMOVIDAS = ['simpay', 'fyhub'];

    public function up(): void
    {
        // Usuários com override apontando para uma removida voltam ao padrão global.
        DB::table('users')
            ->whereIn('preferred_adquirente', self::REMOVIDAS)
            ->update([
                'preferred_adquirente' => null,
                'adquirente_override' => 0,
                'updated_at' => now(),
            ]);

        DB::table('users')
            ->whereIn('preferred_adquirente_card_billet', self::REMOVIDAS)
            ->update([
                'preferred_adquirente_card_billet' => null,
                'adquirente_card_billet_override' => 0,
                'updated_at' => now(),
            ]);

        // Se a global (is_default) era uma removida, promove treeal antes de apagar,
        // senão Helper::adquirenteDefault() passa a devolver null e o PIX para.
        $defaultRemovida = DB::table('adquirentes')
            ->whereIn('referencia', self::REMOVIDAS)
            ->where('is_default', 1)
            ->exists();

        if ($defaultRemovida) {
            DB::table('adquirentes')->where('is_default', 1)->update(['is_default' => 0]);
            DB::table('adquirentes')
                ->where('referencia', 'treeal')
                ->update(['is_default' => 1, 'status' => 1, 'updated_at' => now()]);
        }

        DB::table('adquirentes')->whereIn('referencia', self::REMOVIDAS)->delete();
    }

    public function down(): void
    {
        // Integrações não existem mais no código: recriar as linhas só reintroduziria
        // adquirentes sem serviço registrado (PixAcquirerManager devolveria Null).
    }
};
