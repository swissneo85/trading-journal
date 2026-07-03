<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_exit_price_avg_is_size_weighted_across_multiple_closes(): void
    {
        Activity::insert([
            ['deal_id' => 'D1', 'date_utc' => '2026-06-01T10:00:00', 'level' => 2000.0, 'open_price' => null, 'size' => 3, 'direction' => 'BUY'],
            // 2 lots @ 2010, 1 lot @ 2030 -> weighted avg = (2*2010 + 1*2030) / 3 = 2016.666...
            ['deal_id' => 'D1', 'date_utc' => '2026-06-01T11:00:00', 'level' => 2010.0, 'open_price' => 2000.0, 'size' => 2, 'direction' => 'SELL'],
            ['deal_id' => 'D1', 'date_utc' => '2026-06-01T12:00:00', 'level' => 2030.0, 'open_price' => 2000.0, 'size' => 1, 'direction' => 'SELL'],
        ]);

        // Single-close deal: exit_price_avg should just be that close's level.
        Activity::insert([
            ['deal_id' => 'D2', 'date_utc' => '2026-06-02T10:00:00', 'level' => 100.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
            ['deal_id' => 'D2', 'date_utc' => '2026-06-02T11:00:00', 'level' => 110.0, 'open_price' => 100.0, 'size' => 1, 'direction' => 'SELL'],
        ]);

        // Still-open deal (no closes yet): exit_price_avg should be null.
        Activity::insert([
            ['deal_id' => 'D3', 'date_utc' => '2026-06-03T10:00:00', 'level' => 50.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
        ]);

        $response = $this->getJson('/trading/trades');
        $response->assertOk();

        $trades = collect($response->json())->keyBy('deal_id');

        $this->assertEqualsWithDelta(2016.6667, $trades['D1']['exit_price_avg'], 0.001);
        $this->assertEquals(110.0, $trades['D2']['exit_price_avg']);
        $this->assertNull($trades['D3']['exit_price_avg']);
    }

    public function test_partial_close_keeps_position_open_with_no_fixed_close_time(): void
    {
        // Exact bug report scenario: 1.0 lot opened short, only 0.25 lots
        // closed so far — 0.75 lots are still running on Capital.com.
        Activity::insert([
            ['deal_id' => 'PARTIAL1', 'date_utc' => '2026-06-01T10:00:00', 'level' => 4184.21, 'open_price' => null, 'size' => 1.0, 'direction' => 'SELL'],
            ['deal_id' => 'PARTIAL1', 'date_utc' => '2026-06-01T11:03:00', 'level' => 4175.63, 'open_price' => 4184.21, 'size' => 0.25, 'direction' => 'BUY'],
        ]);

        $trade = collect($this->getJson('/trading/trades')->json())->firstWhere('deal_id', 'PARTIAL1');

        $this->assertSame('partial', $trade['status']);
        $this->assertNull($trade['close_time'], 'close_time must stay null while lots remain open');
        $this->assertSame(1, $trade['num_exits']);
    }

    public function test_position_closed_in_a_single_step_is_reported_as_closed(): void
    {
        // Regression guard: a plain, non-partial close (opened and closed
        // lot sizes match exactly) must still resolve to 'closed' with a
        // real close_time, exactly as before this fix.
        Activity::insert([
            ['deal_id' => 'FULL1', 'date_utc' => '2026-06-01T10:00:00', 'level' => 100.0, 'open_price' => null, 'size' => 1.0, 'direction' => 'BUY'],
            ['deal_id' => 'FULL1', 'date_utc' => '2026-06-01T11:00:00', 'level' => 105.0, 'open_price' => 100.0, 'size' => 1.0, 'direction' => 'SELL'],
        ]);

        $trade = collect($this->getJson('/trading/trades')->json())->firstWhere('deal_id', 'FULL1');

        $this->assertSame('closed', $trade['status']);
        $this->assertSame('2026-06-01T11:00:00', $trade['close_time']);
    }

    public function test_position_closed_via_multiple_partials_summing_to_full_size_is_closed(): void
    {
        // Two partial closes that together account for the whole opened
        // size must resolve to fully 'closed', not stuck as 'partial'
        // forever due to floating-point drift (hence SIZE_TOLERANCE).
        Activity::insert([
            ['deal_id' => 'FULL2', 'date_utc' => '2026-06-01T10:00:00', 'level' => 100.0, 'open_price' => null, 'size' => 1.0, 'direction' => 'BUY'],
            ['deal_id' => 'FULL2', 'date_utc' => '2026-06-01T11:00:00', 'level' => 102.0, 'open_price' => 100.0, 'size' => 0.4, 'direction' => 'SELL'],
            ['deal_id' => 'FULL2', 'date_utc' => '2026-06-01T12:00:00', 'level' => 104.0, 'open_price' => 100.0, 'size' => 0.6, 'direction' => 'SELL'],
        ]);

        $trade = collect($this->getJson('/trading/trades')->json())->firstWhere('deal_id', 'FULL2');

        $this->assertSame('closed', $trade['status']);
        $this->assertSame('2026-06-01T12:00:00', $trade['close_time']);
    }

    public function test_fully_open_position_with_zero_exits_is_reported_as_open(): void
    {
        Activity::insert([
            ['deal_id' => 'OPEN1', 'date_utc' => '2026-06-01T10:00:00', 'level' => 50.0, 'open_price' => null, 'size' => 2.0, 'direction' => 'BUY'],
        ]);

        $trade = collect($this->getJson('/trading/trades')->json())->firstWhere('deal_id', 'OPEN1');

        $this->assertSame('open', $trade['status']);
        $this->assertNull($trade['close_time']);
        $this->assertSame(0, $trade['num_exits']);
    }
}
