<?php

use App\Console\Commands\FetchCapitalHistory;
use App\Console\Commands\PollCapitalPositions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(PollCapitalPositions::class)->everyMinute()->withoutOverlapping();
Schedule::command(FetchCapitalHistory::class)->dailyAt('00:05')->withoutOverlapping();
