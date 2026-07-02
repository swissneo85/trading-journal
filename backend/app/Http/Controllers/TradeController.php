<?php

namespace App\Http\Controllers;

use App\Models\TradeTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TradeController extends Controller
{
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
                       MAX(a.date_utc) AS close_time
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

        $rows = DB::select(<<<'SQL'
            SELECT
                a.deal_id AS deal_id,
                a.date_utc AS date_utc,
                a.epic AS epic,
                a.instrument AS instrument,
                a.direction AS direction,
                a.size AS size,
                a.level AS level,
                a.open_price AS open_price,
                a.source AS source,
                (
                    SELECT t.pl_chf FROM transactions t
                    WHERE t.deal_id = a.deal_id
                      AND t.transaction_type = 'TRADE'
                      AND ABS(strftime('%s', t.date_utc) - strftime('%s', a.date_utc)) <= 2
                    LIMIT 1
                ) AS pl_chf
            FROM activities a
            WHERE a.deal_id = ?
            ORDER BY a.date_utc ASC
        SQL, [$validated['dealId']]);

        return response()->json($rows);
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
