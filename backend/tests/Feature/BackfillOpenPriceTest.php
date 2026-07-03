<?php

namespace Tests\Feature;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillOpenPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_repairs_a_deal_with_zero_null_open_price_rows(): void
    {
        // Simulate the exact corruption described in the bug report: the
        // true opening row got its open_price flipped away from NULL by
        // the old buggy sync logic, leaving two "Schliessung"-looking rows.
        Activity::insert([
            ['deal_id' => 'CORRUPT1', 'date_utc' => '2026-06-01T10:00:00', 'level' => 2000.0, 'open_price' => 2000.0, 'size' => 1, 'direction' => 'BUY'],
            ['deal_id' => 'CORRUPT1', 'date_utc' => '2026-06-01T12:00:00', 'level' => 2050.0, 'open_price' => 2000.0, 'size' => 1, 'direction' => 'SELL'],
        ]);

        // A healthy deal must be left untouched.
        Activity::insert([
            ['deal_id' => 'HEALTHY1', 'date_utc' => '2026-06-02T10:00:00', 'level' => 100.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
            ['deal_id' => 'HEALTHY1', 'date_utc' => '2026-06-02T11:00:00', 'level' => 105.0, 'open_price' => 100.0, 'size' => 1, 'direction' => 'SELL'],
        ]);

        // A single-row (still-open) deal must not be touched or miscounted.
        Activity::insert([
            ['deal_id' => 'STILLOPEN1', 'date_utc' => '2026-06-03T10:00:00', 'level' => 50.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
        ]);

        $this->artisan('trading:backfill-open-price')
            ->expectsOutputToContain('1 betroffene(r) Deal(s) gefunden: CORRUPT1')
            ->expectsOutputToContain('1 Activity-Zeile(n) korrigiert bei 1 betroffenen Deal(s)')
            ->assertSuccessful();

        $rows = Activity::where('deal_id', 'CORRUPT1')->orderBy('date_utc')->get();
        $this->assertNull($rows[0]->open_price, 'earliest row must be restored to NULL (Eröffnung)');
        $this->assertSame(2000.0, $rows[1]->open_price, 'later row must keep the opening level');

        $healthy = Activity::where('deal_id', 'HEALTHY1')->orderBy('date_utc')->get();
        $this->assertNull($healthy[0]->open_price);
        $this->assertSame(100.0, $healthy[1]->open_price);
    }

    public function test_no_op_when_nothing_is_corrupted(): void
    {
        Activity::insert([
            ['deal_id' => 'HEALTHY2', 'date_utc' => '2026-06-02T10:00:00', 'level' => 100.0, 'open_price' => null, 'size' => 1, 'direction' => 'BUY'],
        ]);

        $this->artisan('trading:backfill-open-price')
            ->expectsOutputToContain('Keine betroffenen Deals gefunden')
            ->assertSuccessful();
    }
}
