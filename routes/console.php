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
