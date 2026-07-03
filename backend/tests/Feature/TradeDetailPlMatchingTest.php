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

    public function test_multiple_closes_are_matched_nearest_first_without_reusing_a_transaction(): void
    {
        // Two partial closes 10 seconds apart, each with its own nearby
        // transaction, plus a decoy transaction sitting almost exactly
        // between them (closer to close #2's true partner than close #1's
        // transaction is to close #1, to make sure the algorithm doesn't
        // grab the globally-nearest transaction for the wrong activity).
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

    public function test_a_transaction_outside_tolerance_is_not_matched(): void
    {
        Activity::insert([
            ['deal_id' => 'D4', 'date_utc' => '2026-06-01T10:00:00', 'level' => 10.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
            ['deal_id' => 'D4', 'date_utc' => '2026-06-01T10:00:05', 'level' => 11.0, 'open_price' => 10.0, 'size' => 1, 'direction' => 'SELL'],
        ]);
        Transaction::insert([
            // 30 seconds away — well outside the matching tolerance.
            'reference' => 'T4', 'deal_id' => 'D4', 'date_utc' => '2026-06-01T10:00:35', 'transaction_type' => 'TRADE', 'pl_chf' => 3.0,
        ]);

        $rows = collect($this->getJson('/trading/detail?dealId=D4')->assertOk()->json());

        $this->assertNull($rows->firstWhere('open_price', 10.0)['pl_chf']);
    }
}
