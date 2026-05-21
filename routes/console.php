<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new \App\Jobs\ReconcileSimpayPayoutsJob)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new \App\Jobs\ReconcileSimpayDepositsJob)
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
