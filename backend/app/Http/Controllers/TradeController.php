<?php

namespace App\Http\Controllers;

use App\Models\TradeTag;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TradeController extends Controller
{
    // How close an activity's and a transaction's date_utc must be to be
    // considered "the same fill" — matches the tolerance the previous
    // implementation used for its (broken) SQL correlation.
    private const MATCH_TOLERANCE_SECONDS = 2;

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
                ex.close_time AS close_time,
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
        SQL);

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
     * the transaction that represents the same fill. Activities and
     * transactions for the same close rarely share an identical date_utc
     * (they're logged by two different Capital.com endpoints), so this
     * matches on time proximity instead of equality — and does so with a
     * proper nearest-neighbor assignment (closest pairs first, each side
     * used at most once) rather than a naive "first match wins" query, so
     * that with several closes seconds apart no transaction gets double-
     * counted or attributed to the wrong event.
     *
     * Previously this ran as a correlated SQL subquery using
     * strftime('%s', ...) on both sides, which always returned NULL (and
     * so pl_chf was always "–" in the UI): SQLite only learned to parse the
     * 'T' in our ISO-8601 timestamps ("...T10:00:00") inside date functions
     * as of 3.42.0, and Debian 12's bundled libsqlite3 (3.40.1) predates
     * that. Carbon::parse() has no such version dependency.
     *
     * @return array<string, float|null> pl_chf keyed by the activity's date_utc
     */
    private function matchClosesToTransactions(Collection $activities, Collection $transactions): array
    {
        $closes = $activities->filter(fn ($a) => $a->open_price !== null)->values();

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
