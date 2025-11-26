<?php

namespace App\Providers;

use App\Events\{ LevelUpdated};
use App\Listeners\{InvalidateGamificationCache, LogLevelChanges};
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service Provider para eventos de gamificação
 * 
 * Registra listeners para eventos de níveis
 * 
 * @package App\Providers
 */
class GamificationEventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        LevelUpdated::class => [
            InvalidateGamificationCache::class . '@handleLevelUpdated',
            LogLevelChanges::class . '@handleLevelUpdated',
        ],
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        parent::boot();
    }
}

