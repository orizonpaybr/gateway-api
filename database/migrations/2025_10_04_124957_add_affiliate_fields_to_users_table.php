<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Campos para sistema de affiliados
            $table->unsignedBigInteger('affiliate_id')->nullable()->after('gerente_id');
            $table->decimal('affiliate_percentage', 5, 2)->default(0.00)->after('gerente_percentage');
            $table->boolean('is_affiliate')->default(false)->after('affiliate_percentage');
            $table->string('affiliate_code', 50)->unique()->nullable()->after('is_affiliate');
            $table->string('affiliate_link')->nullable()->after('affiliate_code');
            
            // Foreign key para o affiliado que indicou este usuário
            $table->foreign('affiliate_id')->references('id')->on('users')->onDelete('set null');
            
            // Index para performance
            $table->index(['is_affiliate', 'affiliate_percentage']);
            
            // Modificar campos existentes se necessário
            if (!Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 50)->nullable()->after('affiliate_link');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['affiliate_id']);
            $table->dropIndex(['is_affiliate', 'affiliate_percentage']);
            $table->dropColumn([
                'affiliate_id',
                'affiliate_percentage', 
                'is_affiliate',
                'affiliate_code',
                'affiliate_link',
                'referral_code'
            ]);
        });
    }
};