<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            ->filter(fn (array $a) => ($a['type'] ?? null) === 'POSITION' && ($a['status'] ?? null) === 'ACCEPTED' && ($a['dealId'] ?? null))
            ->values();

        if ($activities->isEmpty()) {
            return 0;
        }

        $rows = $activities->map(fn (array $activity) => [
            // Trimmed defensively: activities and transactions come from two
            // different Capital.com endpoints, and the P/L join in
            // TradeController relies on deal_id matching byte-for-byte
            // between the two tables.
            'deal_id' => trim((string) $activity['dealId']),
            'date_utc' => $activity['date'] ?? null,
            'epic' => $activity['epic'] ?? null,
            'instrument' => $activity['marketName'] ?? null,
            'direction' => $activity['details']['direction'] ?? null,
            'size' => $activity['details']['size'] ?? null,
            'level' => $activity['details']['level'] ?? null,
            // Recomputed below from the full stored row set per deal — never
            // derived here from a possibly-stale in-flight value, so
            // re-syncing the same activity any number of times can't flip
            // an opening row into a closing one (see recomputeOpenPrices()).
            'open_price' => null,
            'source' => $activity['source'] ?? null,
        ])->all();

        Activity::upsert($rows, ['deal_id', 'date_utc']);

        $this->recomputeOpenPrices(collect($rows)->pluck('deal_id')->unique()->all());

        return count($rows);
    }

    /**
     * Opening classification is structural, not incremental: for every
     * affected deal, the chronologically first *stored* activity is always
     * the opening (open_price = null); every other activity for that deal
     * gets open_price = the opening's level. Recomputing from the full
     * stored row set (rather than trusting whatever open_price a previous
     * sync happened to write) makes this idempotent no matter how many
     * times, or in what order, an activity gets re-synced — overlapping
     * poll/history windows can no longer corrupt an already-correct
     * opening row into looking like a closing one.
     *
     * Also used standalone by trading:backfill-open-price to repair rows
     * that were already corrupted by the old incremental logic. Returns how
     * many rows actually had their open_price value changed.
     */
    public function recomputeOpenPrices(array $dealIds): int
    {
        $changed = 0;

        foreach ($dealIds as $dealId) {
            $rows = Activity::where('deal_id', $dealId)->orderBy('date_utc')->get(['date_utc', 'level', 'open_price']);

            if ($rows->isEmpty()) {
                continue;
            }

            $opening = $rows->first();

            foreach ($rows as $row) {
                $correctOpenPrice = $row->date_utc === $opening->date_utc ? null : $opening->level;

                if ($row->open_price !== $correctOpenPrice) {
                    Activity::where('deal_id', $dealId)
                        ->where('date_utc', $row->date_utc)
                        ->update(['open_price' => $correctOpenPrice]);
                    $changed++;
                }
            }
        }

        return $changed;
    }

    private function importTransactions(int $lastPeriod): int
    {
        $transactions = $this->capital->getTransactions($lastPeriod)['transactions'] ?? [];
        $count = 0;

        foreach ($transactions as $transaction) {
            // Normalized defensively (case/whitespace) so a formatting quirk
            // on one row can't silently drop it from the strict allow-list
            // below.
            $type = strtoupper(trim((string) ($transaction['transactionType'] ?? '')));

            if (! in_array($type, ['TRADE', 'CASH_IN', 'CASH_OUT'], true)) {
                continue;
            }

            $reference = $transaction['reference'] ?? null;
            if (! $reference) {
                continue;
            }

            try {
                Transaction::updateOrCreate(
                    ['reference' => $reference],
                    [
                        // Trimmed to match the same normalization applied to
                        // activities.deal_id — see importActivities().
                        'deal_id' => isset($transaction['dealId']) ? trim((string) $transaction['dealId']) : null,
                        'date_utc' => $transaction['dateUtc'] ?? $transaction['date'] ?? null,
                        'instrument' => $transaction['instrumentName'] ?? null,
                        'transaction_type' => $type,
                        // Despite the name, Capital.com's transaction "size" field
                        // holds the P/L value in CHF for TRADE rows — there is no
                        // separate profitAndLoss field on this endpoint.
                        'pl_chf' => isset($transaction['size']) ? (float) $transaction['size'] : null,
                        'note' => $transaction['note'] ?? null,
                    ]
                );

                $count++;
            } catch (Throwable $e) {
                // One malformed/unexpected row must not blackhole every
                // other transaction in this batch — including ones for
                // other deal_ids further down the same API response.
                Log::warning('HistorySyncService: Transaction '.$reference.' konnte nicht gespeichert werden: '.$e->getMessage());
            }
        }

        return $count;
    }
}
