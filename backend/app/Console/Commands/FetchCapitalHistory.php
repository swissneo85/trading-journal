<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Services\HistorySyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('trading:fetch-history {--days=1 : Lookback window in Tagen (Standard: 1 = 24h, wie bisher)}')]
#[Description('Holt Activity- und Transaction-Historie von Capital.com und speichert sie')]
class FetchCapitalHistory extends Command
{
    public function handle(HistorySyncService $historySync): int
    {
        // --days lets a backfill cover positions whose activity/transaction
        // only became queryable outside the normal 24h daily window (e.g.
        // after downtime, or a broker-side settlement delay) - the default
        // of 1 day keeps the scheduled run's behavior unchanged.
        $lastPeriod = (int) $this->option('days') * 86400;

        try {
            $result = $historySync->sync($lastPeriod);

            ActivityLog::log('history_fetch',
                "History: {$result['activities']} Activities, {$result['transactions']} Transactions");
        } catch (Throwable $e) {
            Log::warning('FetchCapitalHistory: '.$e->getMessage());
            ActivityLog::log('error', 'FetchCapitalHistory: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
