<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Services\HistorySyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('trading:fetch-history')]
#[Description('Holt Activity- und Transaction-Historie von Capital.com und speichert sie')]
class FetchCapitalHistory extends Command
{
    public function handle(HistorySyncService $historySync): int
    {
        try {
            $result = $historySync->sync();

            ActivityLog::log('history_fetch',
                "History: {$result['activities']} Activities, {$result['transactions']} Transactions");
        } catch (Throwable $e) {
            Log::warning('FetchCapitalHistory: '.$e->getMessage());
            ActivityLog::log('error', 'FetchCapitalHistory: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
