<?php

namespace App\Http\Controllers;

use App\Models\TradeTag;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TradeController extends Controller
{
    // Fallback tolerance for matching an activity to a transaction by time
    // proximity, only used when rank-based matching (see
    // matchClosesToTransactions()) doesn't apply because the two sides
    // don't have the same count. Capital.com's transaction/settlement
    // timestamp can lag the activity's fill timestamp by an unpredictable
    // amount (observed to exceed the previous 2s tolerance in production —
    // it's set generously wide here since this path is already a degraded-
    // data fallback, and it's still scoped to a single deal_id).
    private const MATCH_TOLERANCE_SECONDS = 300;

    // Rounding-error tolerance when comparing the sum of closed lot sizes
    // against the original opening size to decide whether a position is
    // fully closed.
    private const SIZE_TOLERANCE = 0.001;

    public function index()
    {
        $trades = DB::select(<<<'SQL'
            SELECT
                fm.deal_id AS deal_id,
                fm.date_utc AS open_time,
                fm.instrument AS instrument,
                fm.direction AS open_direction,
                fm.level AS entry_price,
                COALESCE(ex.num_exits, 0) AS num_exits,
                ex.exit_reasons AS exit_reasons,
                -- A position is only "closed" once every opened lot has a
                -- matching closing activity — a partial close (closed_size
                -- < opening size) must keep showing as open, not as a
                -- finished trade with a fixed duration.
                CASE
                    WHEN COALESCE(ex.closed_size, 0) <= 0 THEN 'open'
                    WHEN COALESCE(ex.closed_size, 0) >= fm.size - ? THEN 'closed'
                    ELSE 'partial'
                END AS status,
                CASE
                    WHEN COALESCE(ex.closed_size, 0) >= fm.size - ? THEN ex.close_time
                    ELSE NULL
                END AS close_time,
                ex.exit_price_avg AS exit_price_avg,
                (
                    SELECT SUM(t.pl_chf) FROM transactions t
                    WHERE t.deal_id = fm.deal_id AND t.transaction_type = 'TRADE'
                ) AS pl_chf,
                tg.quelle AS quelle,
                tg.notiz AS notiz
            FROM (
                SELECT a.*
                FROM activities a
                INNER JOIN (
                    SELECT deal_id, MIN(date_utc) AS min_date FROM activities GROUP BY deal_id
                ) m ON m.deal_id = a.deal_id AND m.min_date = a.date_utc
            ) fm
            LEFT JOIN (
                SELECT a.deal_id AS deal_id,
                       COUNT(*) AS num_exits,
                       GROUP_CONCAT(DISTINCT a.source) AS exit_reasons,
                       MAX(a.date_utc) AS close_time,
                       SUM(a.size) AS closed_size,
                       -- Size-weighted average exit price across every
                       -- closing activity for this deal (a single close
                       -- collapses to just that close's level).
                       SUM(a.level * a.size) / NULLIF(SUM(a.size), 0) AS exit_price_avg
                FROM activities a
                INNER JOIN (
                    SELECT deal_id, MIN(date_utc) AS min_date FROM activities GROUP BY deal_id
                ) m ON m.deal_id = a.deal_id
                WHERE a.date_utc <> m.min_date
                GROUP BY a.deal_id
            ) ex ON ex.deal_id = fm.deal_id
            LEFT JOIN trade_tags tg ON tg.deal_id = fm.deal_id
            ORDER BY fm.date_utc DESC
        SQL, [self::SIZE_TOLERANCE, self::SIZE_TOLERANCE]);

        return response()->json($trades);
    }

    public function detail(Request $request)
    {
        $validated = $request->validate([
            'dealId' => 'required|string',
        ]);

        $activities = DB::table('activities')
            ->where('deal_id', $validated['dealId'])
            ->orderBy('date_utc')
            ->get(['deal_id', 'date_utc', 'epic', 'instrument', 'direction', 'size', 'level', 'open_price', 'source']);

        $transactions = DB::table('transactions')
            ->where('deal_id', $validated['dealId'])
            ->where('transaction_type', 'TRADE')
            ->get(['date_utc', 'pl_chf']);

        $plByDate = $this->matchClosesToTransactions($activities, $transactions);

        $rows = $activities->map(fn ($activity) => (array) $activity + [
            'pl_chf' => $plByDate[$activity->date_utc] ?? null,
        ])->values();

        return response()->json($rows);
    }

    /**
     * Pairs each closing activity (open_price IS NOT NULL) with the P/L of
     * the transaction that represents the same fill.
     *
     * Primary strategy: rank-based. When a deal has exactly as many TRADE
     * transactions as closing activities, the Nth close (by date_utc) is
     * paired with the Nth transaction (by date_utc) — Capital.com always
     * records a position's closes and their transactions in the same
     * order, even though the transaction's timestamp can lag the
     * activity's fill timestamp by a large and apparently variable amount
     * (observed in production to exceed several minutes, not the seconds
     * this endpoint originally assumed). Rank-based matching needs no
     * assumption about that lag at all.
     *
     * Fallback: if the counts don't match (e.g. a transaction failed to
     * sync — see the earlier resilience work in HistorySyncService), rank
     * pairing isn't safe, so fall back to time-proximity matching within
     * MATCH_TOLERANCE_SECONDS, with a proper nearest-neighbor assignment
     * (closest pairs first, each side used at most once) so that with
     * several closes seconds apart no transaction gets double-counted or
     * attributed to the wrong event.
     *
     * Earlier history: this originally ran as a correlated SQL subquery
     * using strftime('%s', ...) on both sides, which always returned NULL
     * (so pl_chf was always "–" in the UI) because SQLite only learned to
     * parse the 'T' in our ISO-8601 timestamps inside date functions as of
     * 3.42.0, predating Debian 12's bundled libsqlite3 (3.40.1). That was
     * replaced with the PHP-side proximity matcher below, which then
     * turned out to still miss real matches because the 2s tolerance it
     * assumed was too tight — hence the rank-based approach now being
     * primary.
     *
     * @return array<string, float|null> pl_chf keyed by the activity's date_utc
     */
    private function matchClosesToTransactions(Collection $activities, Collection $transactions): array
    {
        $closes = $activities->filter(fn ($a) => $a->open_price !== null)->sortBy('date_utc')->values();
        $txns = $transactions->sortBy('date_utc')->values();

        if ($closes->isNotEmpty() && $closes->count() === $txns->count()) {
            $plByDate = [];

            foreach ($closes as $i => $activity) {
                $plByDate[$activity->date_utc] = $txns[$i]->pl_chf;
            }

            return $plByDate;
        }

        return $this->matchClosesByTimeProximity($closes, $txns);
    }

    /**
     * @return array<string, float|null> pl_chf keyed by the activity's date_utc
     */
    private function matchClosesByTimeProximity(Collection $closes, Collection $transactions): array
    {
        $candidates = [];
        foreach ($closes as $activity) {
            $activityTime = Carbon::parse($activity->date_utc);

            foreach ($transactions as $ti => $transaction) {
                $diff = abs($activityTime->diffInSeconds(Carbon::parse($transaction->date_utc)));

                if ($diff <= self::MATCH_TOLERANCE_SECONDS) {
                    $candidates[] = [$diff, $activity->date_utc, $ti];
                }
            }
        }

        usort($candidates, fn ($a, $b) => $a[0] <=> $b[0]);

        $plByDate = [];
        $usedTransactions = [];

        foreach ($candidates as [$diff, $activityDate, $transactionIndex]) {
            if (isset($plByDate[$activityDate]) || isset($usedTransactions[$transactionIndex])) {
                continue;
            }

            $plByDate[$activityDate] = $transactions[$transactionIndex]->pl_chf;
            $usedTransactions[$transactionIndex] = true;
        }

        return $plByDate;
    }

    public function tag(Request $request)
    {
        $validated = $request->validate([
            'dealId' => 'required|string',
            'quelle' => 'nullable|string',
            'notiz' => 'nullable|string',
        ]);

        TradeTag::updateOrCreate(
            ['deal_id' => $validated['dealId']],
            [
                'quelle' => $validated['quelle'] ?? null,
                'notiz' => $validated['notiz'] ?? null,
                'tagged_at' => now()->toIso8601String(),
            ]
        );

        return response()->json(['message' => '✅ Tag gespeichert']);
    }
}
