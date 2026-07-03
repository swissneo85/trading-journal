<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeDetailPlMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_row_never_gets_a_pl_and_close_gets_the_matching_transactions_pl(): void
    {
        Activity::insert([
            ['deal_id' => 'D1', 'date_utc' => '2026-06-01T10:00:00', 'level' => 2000.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
            ['deal_id' => 'D1', 'date_utc' => '2026-06-01T12:00:01', 'level' => 2050.0, 'open_price' => 2000.0, 'size' => 1, 'direction' => 'SELL'],
        ]);
        // Transaction logged 1 second after the activity — same fill, just
        // recorded by a different Capital.com endpoint.
        Transaction::insert([
            'reference' => 'T1', 'deal_id' => 'D1', 'date_utc' => '2026-06-01T12:00:00',
            'transaction_type' => 'TRADE', 'pl_chf' => 42.5,
        ]);

        $rows = collect($this->getJson('/trading/detail?dealId=D1')->assertOk()->json());

        $this->assertNull($rows->firstWhere('open_price', null)['pl_chf'], 'opening row must never carry a P/L');
        $this->assertSame(42.5, $rows->firstWhere('open_price', 2000.0)['pl_chf']);
    }

    public function test_real_partial_close_scenario_with_transactions_lagging_far_beyond_the_old_2s_tolerance(): void
    {
        // The exact scenario from production that PR #33's 2s-tolerance
        // proximity matcher failed on: opening 03.07. 15:15:31 short Gold
        // @4184.21, two 0.25-lot partial closes, combined P/L +3.76 CHF.
        // The real transaction timestamps couldn't be pulled (no DB access
        // from this environment), so the delays below (63s / 76s) are
        // illustrative stand-ins for "however long Capital.com's real
        // settlement lag turns out to be" — the point of rank-based
        // matching is that it doesn't need that number at all, as long as
        // the close and transaction counts agree.
        Activity::insert([
            ['deal_id' => 'REAL1', 'date_utc' => '2026-07-03T15:15:31', 'level' => 4184.21, 'open_price' => null, 'size' => 1.0, 'direction' => 'SELL'],
            ['deal_id' => 'REAL1', 'date_utc' => '2026-07-03T16:18:33', 'level' => 4175.63, 'open_price' => 4184.21, 'size' => 0.25, 'direction' => 'BUY'],
            ['deal_id' => 'REAL1', 'date_utc' => '2026-07-03T16:21:01', 'level' => 4173.94, 'open_price' => 4184.21, 'size' => 0.25, 'direction' => 'BUY'],
        ]);
        Transaction::insert([
            // 63s and 76s after their respective activities — both well
            // past the old 2s tolerance, and even past a naively "generous"
            // few-second bump, which is exactly why rank-based matching
            // (not a wider tolerance) is the real fix.
            ['reference' => 'RT1', 'deal_id' => 'REAL1', 'date_utc' => '2026-07-03T16:19:36', 'transaction_type' => 'TRADE', 'pl_chf' => 2.15],
            ['reference' => 'RT2', 'deal_id' => 'REAL1', 'date_utc' => '2026-07-03T16:22:17', 'transaction_type' => 'TRADE', 'pl_chf' => 1.61],
        ]);

        $rows = collect($this->getJson('/trading/detail?dealId=REAL1')->assertOk()->json());

        $opening = $rows->firstWhere('open_price', null);
        $close1 = $rows->firstWhere('date_utc', '2026-07-03T16:18:33');
        $close2 = $rows->firstWhere('date_utc', '2026-07-03T16:21:01');

        $this->assertNull($opening['pl_chf']);
        $this->assertEquals(2.15, $close1['pl_chf']);
        $this->assertEquals(1.61, $close2['pl_chf']);
        $this->assertEqualsWithDelta(3.76, $close1['pl_chf'] + $close2['pl_chf'], 0.001, 'per-event P/Ls must sum to the total shown at the top of the modal');

        $totalFromIndex = collect($this->getJson('/trading/trades')->json())->firstWhere('deal_id', 'REAL1')['pl_chf'];
        $this->assertEqualsWithDelta(3.76, $totalFromIndex, 0.001);
    }

    public function test_multiple_closes_with_matching_transaction_count_are_paired_by_chronological_rank(): void
    {
        // Two partial closes 10 seconds apart, each with its own nearby
        // transaction — since the counts match (2 closes, 2 transactions),
        // this goes through rank-based matching, not time proximity.
        Activity::insert([
            ['deal_id' => 'D2', 'date_utc' => '2026-06-01T10:00:00', 'level' => 100.0, 'open_price' => null, 'size' => 3, 'direction' => 'BUY'],
            ['deal_id' => 'D2', 'date_utc' => '2026-06-01T10:00:10', 'level' => 105.0, 'open_price' => 100.0, 'size' => 1, 'direction' => 'SELL'],
            ['deal_id' => 'D2', 'date_utc' => '2026-06-01T10:00:20', 'level' => 106.0, 'open_price' => 100.0, 'size' => 2, 'direction' => 'SELL'],
        ]);
        Transaction::insert([
            ['reference' => 'T2a', 'deal_id' => 'D2', 'date_utc' => '2026-06-01T10:00:09', 'transaction_type' => 'TRADE', 'pl_chf' => 5.0],
            ['reference' => 'T2b', 'deal_id' => 'D2', 'date_utc' => '2026-06-01T10:00:21', 'transaction_type' => 'TRADE', 'pl_chf' => 12.0],
        ]);

        $rows = collect($this->getJson('/trading/detail?dealId=D2')->assertOk()->json());

        $close1 = $rows->first(fn ($r) => $r['date_utc'] === '2026-06-01T10:00:10');
        $close2 = $rows->first(fn ($r) => $r['date_utc'] === '2026-06-01T10:00:20');

        // assertEquals, not assertSame: whole-number floats (5.0, 12.0)
        // round-trip through JSON as ints (5, 12), which is fine — only the
        // numeric value matters here.
        $this->assertEquals(5.0, $close1['pl_chf']);
        $this->assertEquals(12.0, $close2['pl_chf']);

        // The sum of the per-event P/Ls must equal the aggregate SUM used
        // by the trades-list endpoint (TradeController::index()).
        $totalFromEvents = $close1['pl_chf'] + $close2['pl_chf'];
        $totalFromIndex = collect($this->getJson('/trading/trades')->json())->firstWhere('deal_id', 'D2')['pl_chf'];
        $this->assertEquals($totalFromIndex, $totalFromEvents);
    }

    public function test_a_transaction_is_never_assigned_to_two_different_closes(): void
    {
        // Two closes close enough together that both are within tolerance
        // of the SAME single transaction. Only one may claim it.
        Activity::insert([
            ['deal_id' => 'D3', 'date_utc' => '2026-06-01T10:00:00', 'level' => 50.0, 'open_price' => null, 'size' => 2, 'direction' => 'BUY'],
            ['deal_id' => 'D3', 'date_utc' => '2026-06-01T10:00:01', 'level' => 51.0, 'open_price' => 50.0, 'size' => 1, 'direction' => 'SELL'],
            ['deal_id' => 'D3', 'date_utc' => '2026-06-01T10:00:02', 'level' => 52.0, 'open_price' => 50.0, 'size' => 1, 'direction' => 'SELL'],
        ]);
        Transaction::insert([
            'reference' => 'T3', 'deal_id' => 'D3', 'date_utc' => '2026-06-01T10:00:01', 'transaction_type' => 'TRADE', 'pl_chf' => 7.0,
        ]);

        $rows = collect($this->getJson('/trading/detail?dealId=D3')->assertOk()->json());

        $matched = $rows->filter(fn ($r) => $r['pl_chf'] !== null);
        $this->assertCount(1, $matched, 'only one of the two competing closes may claim the single transaction');
        // The closer one (0s away) must win over the farther one (1s away).
        $this->assertSame('2026-06-01T10:00:01', $matched->first()['date_utc']);
    }

    public function test_single_close_is_rank_matched_to_its_transaction_even_when_far_apart(): void
    {
        // With exactly one close and one transaction, counts match (1:1),
        // so rank-based matching applies regardless of the time gap — this
        // is precisely the behavior that fixes the real bug (a 30s+ gap
        // used to leave this permanently unmatched under the old
        // tolerance-only approach).
        Activity::insert([
            ['deal_id' => 'D4', 'date_utc' => '2026-06-01T10:00:00', 'level' => 10.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
            ['deal_id' => 'D4', 'date_utc' => '2026-06-01T10:00:05', 'level' => 11.0, 'open_price' => 10.0, 'size' => 1, 'direction' => 'SELL'],
        ]);
        Transaction::insert([
            'reference' => 'T4', 'deal_id' => 'D4', 'date_utc' => '2026-06-01T10:00:35', 'transaction_type' => 'TRADE', 'pl_chf' => 3.0,
        ]);

        $rows = collect($this->getJson('/trading/detail?dealId=D4')->assertOk()->json());

        $this->assertEquals(3.0, $rows->firstWhere('open_price', 10.0)['pl_chf']);
    }

    public function test_close_with_no_transaction_at_all_stays_unmatched(): void
    {
        // Count mismatch (1 close, 0 transactions) falls back to time-
        // proximity matching, which correctly finds nothing to pair.
        Activity::insert([
            ['deal_id' => 'D5', 'date_utc' => '2026-06-01T10:00:00', 'level' => 20.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
            ['deal_id' => 'D5', 'date_utc' => '2026-06-01T10:00:05', 'level' => 21.0, 'open_price' => 20.0, 'size' => 1, 'direction' => 'SELL'],
        ]);

        $rows = collect($this->getJson('/trading/detail?dealId=D5')->assertOk()->json());

        $this->assertNull($rows->firstWhere('open_price', 20.0)['pl_chf']);
    }

    public function test_transaction_beyond_even_the_widened_fallback_tolerance_is_not_matched(): void
    {
        // Count mismatch (2 closes, 1 transaction) forces the proximity
        // fallback; a transaction over an hour away from every close must
        // still be rejected even by the widened MATCH_TOLERANCE_SECONDS.
        Activity::insert([
            ['deal_id' => 'D6', 'date_utc' => '2026-06-01T10:00:00', 'level' => 30.0, 'open_price' => null, 'size' => 2, 'direction' => 'BUY'],
            ['deal_id' => 'D6', 'date_utc' => '2026-06-01T10:00:01', 'level' => 31.0, 'open_price' => 30.0, 'size' => 1, 'direction' => 'SELL'],
            ['deal_id' => 'D6', 'date_utc' => '2026-06-01T10:00:02', 'level' => 32.0, 'open_price' => 30.0, 'size' => 1, 'direction' => 'SELL'],
        ]);
        Transaction::insert([
            'reference' => 'T6', 'deal_id' => 'D6', 'date_utc' => '2026-06-01T12:00:00', 'transaction_type' => 'TRADE', 'pl_chf' => 9.0,
        ]);

        $rows = collect($this->getJson('/trading/detail?dealId=D6')->assertOk()->json());

        $this->assertTrue($rows->every(fn ($r) => $r['pl_chf'] === null));
    }
}
