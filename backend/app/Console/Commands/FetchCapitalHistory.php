<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Transaction;
use App\Services\CapitalApiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('trading:fetch-history')]
#[Description('Holt Activity- und Transaction-Historie von Capital.com und speichert sie')]
class FetchCapitalHistory extends Command
{
    public function handle(CapitalApiService $capital): int
    {
        try {
            $this->importActivities($capital);
            $this->importTransactions($capital);
        } catch (Throwable $e) {
            Log::warning('FetchCapitalHistory: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function importActivities(CapitalApiService $capital): void
    {
        $activities = $capital->getActivity(86400)['activities'] ?? [];

        foreach ($activities as $activity) {
            if (($activity['type'] ?? null) !== 'POSITION' || ($activity['status'] ?? null) !== 'ACCEPTED') {
                continue;
            }

            $details = $activity['details'] ?? [];

            Activity::upsert([
                'deal_id' => $activity['dealId'] ?? null,
                'date_utc' => $activity['date'] ?? null,
                'epic' => $activity['epic'] ?? null,
                'instrument' => $activity['marketName'] ?? null,
                'direction' => $details['direction'] ?? null,
                'size' => $details['size'] ?? null,
                'level' => $details['level'] ?? null,
                'open_price' => $details['level'] ?? null,
                'source' => $activity['source'] ?? null,
            ], ['deal_id', 'date_utc']);
        }
    }

    private function importTransactions(CapitalApiService $capital): void
    {
        $transactions = $capital->getTransactions(86400)['transactions'] ?? [];

        foreach ($transactions as $transaction) {
            $type = $transaction['transactionType'] ?? null;

            if (! in_array($type, ['TRADE', 'CASH_IN', 'CASH_OUT'], true)) {
                continue;
            }

            Transaction::updateOrCreate(
                ['reference' => $transaction['reference'] ?? null],
                [
                    'deal_id' => $transaction['dealId'] ?? null,
                    'date_utc' => $transaction['dateUtc'] ?? $transaction['date'] ?? null,
                    'instrument' => $transaction['instrumentName'] ?? null,
                    'transaction_type' => $type,
                    'pl_chf' => $transaction['profitAndLoss'] ?? null,
                    'note' => $transaction['note'] ?? null,
                ]
            );
        }
    }
}
