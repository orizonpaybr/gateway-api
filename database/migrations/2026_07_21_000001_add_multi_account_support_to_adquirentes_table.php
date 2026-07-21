<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite múltiplas "nominais" (contas/credenciais) por adquirente.
     *
     * `referencia` continua sendo o identificador único de cada linha
     * (usado em Helper::adquirenteDefault / PixAcquirerManager::resolve),
     * mas passa a poder divergir de `provider` — que identifica a família
     * de serviço registrada em PixAcquirerManager (ex: várias linhas com
     * provider=fluxpayments, cada uma com credenciais próprias).
     *
     * `credentials` fica nulo nas linhas já existentes: elas continuam
     * lendo do .env exatamente como hoje (zero mudança de comportamento).
     */
    public function up(): void
    {
        Schema::table('adquirentes', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('referencia');
            $table->text('credentials')->nullable()->after('provider');
        });

        DB::table('adquirentes')->whereNull('provider')->update([
            'provider' => DB::raw('referencia'),
        ]);

        Schema::table('adquirentes', function (Blueprint $table) {
            $table->unique('referencia');
        });
    }

    public function down(): void
    {
        Schema::table('adquirentes', function (Blueprint $table) {
            $table->dropUnique(['referencia']);
            $table->dropColumn(['provider', 'credentials']);
        });
    }
};
