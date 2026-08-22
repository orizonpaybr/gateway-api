<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.saldo era FLOAT — a ÚNICA coluna de dinheiro em ponto flutuante do schema.
 * Float acumula erro binário (0.1 + 0.2 != 0.3) → drift de centavo no saldo real,
 * que é lido/escrito em TODO depósito/saque/estorno. Migra para DECIMAL(15,2)
 * (exato, base-10), alinhando com as demais colunas financeiras.
 *
 * ⚠️ COLUNA QUENTE. Mudança de TIPO (float→decimal) no MySQL usa ALGORITHM=COPY
 * (rebuild da tabela, lock durante o ALTER) — NÃO é INPLACE. Portanto:
 *   - Fazer BACKUP da tabela `users` antes.
 *   - Rodar em janela de baixo tráfego OU usar pt-online-schema-change / gh-ost
 *     para zero-downtime.
 * O cast float→decimal arredonda para 2 casas, o que já CORRIGE o drift existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'saldo')) {
            DB::statement('ALTER TABLE `users` MODIFY `saldo` DECIMAL(15,2) NOT NULL DEFAULT 0.00');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'saldo')) {
            DB::statement('ALTER TABLE `users` MODIFY `saldo` FLOAT NOT NULL DEFAULT 0');
        }
    }
};
