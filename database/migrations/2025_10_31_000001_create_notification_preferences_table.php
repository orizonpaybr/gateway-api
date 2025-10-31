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
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->unique();
            
            // Canal de notificação (apenas push)
            $table->boolean('push_enabled')->default(true);
            
            // Preferências por tipo de evento
            $table->boolean('notify_transactions')->default(true);
            $table->boolean('notify_deposits')->default(true);
            $table->boolean('notify_withdrawals')->default(true);
            $table->boolean('notify_security')->default(true);
            $table->boolean('notify_system')->default(true);
            
            $table->timestamps();
            
            // Índices para performance
            $table->index(['user_id', 'push_enabled']);
            
            // Foreign key
            $table->foreign('user_id')
                  ->references('username')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};

