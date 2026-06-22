<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('pix_infracoes')) {
            return;
        }

        Schema::table('pix_infracoes', function (Blueprint $table) {
            if (! Schema::hasColumn('pix_infracoes', 'provider')) {
                $table->string('provider', 50)->nullable()->default('treeal')->after('user_id');
            }
            if (! Schema::hasColumn('pix_infracoes', 'provider_infraction_id')) {
                $table->string('provider_infraction_id', 191)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('pix_infracoes', 'analysis_result')) {
                $table->string('analysis_result', 50)->nullable()->after('status');
            }
        });

        // Índice único do id da infração na adquirente (idempotência do webhook).
        if (Schema::hasColumn('pix_infracoes', 'provider_infraction_id')
            && ! $this->indexExists('pix_infracoes', 'pixinf_provider_infraction_id_unique')) {
            Schema::table('pix_infracoes', function (Blueprint $table) {
                $table->unique('provider_infraction_id', 'pixinf_provider_infraction_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pix_infracoes')) {
            return;
        }

        if ($this->indexExists('pix_infracoes', 'pixinf_provider_infraction_id_unique')) {
            Schema::table('pix_infracoes', function (Blueprint $table) {
                $table->dropUnique('pixinf_provider_infraction_id_unique');
            });
        }

        Schema::table('pix_infracoes', function (Blueprint $table) {
            foreach (['provider', 'provider_infraction_id', 'analysis_result'] as $column) {
                if (Schema::hasColumn('pix_infracoes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            $dbName = $connection->getDatabaseName();
            $result = $connection->select(
                'SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
                [$dbName, $table, $index]
            );

            return ! empty($result) && (int) ($result[0]->c ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
