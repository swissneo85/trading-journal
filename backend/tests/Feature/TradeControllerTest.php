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
}
