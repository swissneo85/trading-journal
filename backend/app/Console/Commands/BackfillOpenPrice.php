<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Services\HistorySyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('trading:backfill-open-price')]
#[Description('Einmaliger Reparaturlauf: stellt open_price=NULL auf der ältesten Activity je Deal wieder her, wo eine überlappende Historie-Sync-Runde sie faelschlich überschrieben hatte')]
class BackfillOpenPrice extends Command
{
    public function handle(HistorySyncService $historySync): int
    {
        // Deals with at least 2 activities (an opening + at least one exit)
        // but zero rows marked as the opening (open_price IS NULL) are
        // exactly the ones corrupted by the old incremental isOpening logic
        // in HistorySyncService::importActivities().
        $dealIds = DB::table('activities')
            ->select('deal_id')
            ->groupBy('deal_id')
            ->havingRaw('COUNT(*) >= 2')
            ->havingRaw('SUM(CASE WHEN open_price IS NULL THEN 1 ELSE 0 END) = 0')
            ->pluck('deal_id')
            ->all();

        if (empty($dealIds)) {
            $this->info('Keine betroffenen Deals gefunden — nichts zu reparieren.');

            return self::SUCCESS;
        }

        $this->info(count($dealIds).' betroffene(r) Deal(s) gefunden: '.implode(', ', $dealIds));

        $changed = $historySync->recomputeOpenPrices($dealIds);

        $message = "Backfill: {$changed} Activity-Zeile(n) korrigiert bei ".count($dealIds).' betroffenen Deal(s)';
        $this->info($message);
        ActivityLog::log('backfill', $message);

        return self::SUCCESS;
    }
}
