<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new \App\Jobs\ReconcileFluxPaymentsPayoutsJob)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new \App\Jobs\ReconcileSimpayDepositsJob)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new \App\Jobs\ReconcileSimpayPayoutsJob)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// FYHUB cash-in: síncrono no cron (não depende de queue worker para liquidar / postback)
Schedule::command('fyhub:reconcile-deposits')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('treeal:reconcile-deposits')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Cash-out: resolve saques presos em PENDING/PROCESSING pela verdade da adquirente.
// Rede de segurança da trava "um saque por vez" (destrava o cliente e corrige saldo).
Schedule::command('pixout:reconcile-stuck')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new \App\Jobs\MonitorLoginFailuresJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('auth:prune-events')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
