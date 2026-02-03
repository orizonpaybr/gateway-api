<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Os campos token e secret são criptografados pelo model UsersKey.
     * O valor criptografado (base64) excede VARCHAR(255).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users_key MODIFY token TEXT NULL');
        DB::statement('ALTER TABLE users_key MODIFY secret TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users_key MODIFY token VARCHAR(255) NULL');
        DB::statement('ALTER TABLE users_key MODIFY secret VARCHAR(255) NULL');
    }
};
