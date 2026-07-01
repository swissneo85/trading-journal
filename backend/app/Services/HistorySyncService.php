<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Transaction;

class HistorySyncService
{
    public function __construct(private CapitalApiService $capital)
    {
    }

    public function sync(int $lastPeriod = 86400): array
    {
        return [
            'activities' => $this->importActivities($lastPeriod),
            'transactions' => $this->importTransactions($lastPeriod),
        ];
    }

    private function importActivities(int $lastPeriod): int
    {
        $activities = collect($this->capital->getActivity($lastPeriod)['activities'] ?? [])
            ->filter(fn (array $a) => ($a['type'] ?? null) === 'POSITION' && ($a['status'] ?? null) === 'ACCEPTED')
            ->sortBy('date');

        $count = 0;

        // open_price is null on a position's first (opening) activity and set to
        // the original entry level on every later (closing/exit) activity for the
        // same deal_id — the frontend uses this to distinguish "Eröffnung" from
        // "Schliessung" rows.
        foreach ($activities->groupBy('dealId') as $dealId => $group) {
            $openLevel = Activity::where('deal_id', $dealId)->orderBy('date_utc')->value('level');

            foreach ($group as $activity) {
                $details = $activity['details'] ?? [];
                $level = $details['level'] ?? null;
                $isOpening = $openLevel === null;

                Activity::upsert([
                    'deal_id' => $dealId,
                    'date_utc' => $activity['date'] ?? null,
                    'epic' => $activity['epic'] ?? null,
                    'instrument' => $activity['marketName'] ?? null,
                    'direction' => $details['direction'] ?? null,
                    'size' => $details['size'] ?? null,
                    'level' => $level,
                    'open_price' => $isOpening ? null : $openLevel,
                    'source' => $activity['source'] ?? null,
                ], ['deal_id', 'date_utc']);

                $count++;

                if ($isOpening) {
                    $openLevel = $level;
                }
            }
        }

        return $count;
    }

    private function importTransactions(int $lastPeriod): int
    {
        $transactions = $this->capital->getTransactions($lastPeriod)['transactions'] ?? [];
        $count = 0;

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

            $count++;
        }

        return $count;
    }
}
