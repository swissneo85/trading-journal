<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Services\CapitalApiService;
use App\Services\HistorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function activityPayload(string $dealId, string $date, ?float $level, string $direction = 'BUY'): array
    {
        return [
            'type' => 'POSITION',
            'status' => 'ACCEPTED',
            'dealId' => $dealId,
            'date' => $date,
            'epic' => 'GOLD',
            'marketName' => 'Gold',
            'source' => 'APP',
            'details' => ['direction' => $direction, 'size' => 1, 'level' => $level],
        ];
    }

    public function test_reprocessing_the_opening_activity_in_a_later_sync_does_not_flip_open_price(): void
    {
        $capital = $this->mock(CapitalApiService::class);
        $historySync = new HistorySyncService($capital);

        // Sync run 1: only the opening activity is visible yet.
        $capital->shouldReceive('getActivity')->once()->andReturn([
            'activities' => [$this->activityPayload('D1', '2026-06-01T10:00:00', 2000.0)],
        ]);
        $capital->shouldReceive('getTransactions')->once()->andReturn(['transactions' => []]);
        $historySync->sync(3600);

        $findOpening = fn () => Activity::where('deal_id', 'D1')->where('date_utc', '2026-06-01T10:00:00')->first();

        $this->assertNull($findOpening()->open_price, 'opening activity should start out correctly classified');

        // Sync run 2 (e.g. the next minute's overlapping 1h-window poll):
        // Capital.com returns the SAME opening activity again, with no new
        // close yet. Before the fix, this alone flipped open_price to a
        // non-null value.
        $capital->shouldReceive('getActivity')->once()->andReturn([
            'activities' => [$this->activityPayload('D1', '2026-06-01T10:00:00', 2000.0)],
        ]);
        $capital->shouldReceive('getTransactions')->once()->andReturn(['transactions' => []]);
        $historySync->sync(3600);

        // Re-queried fresh rather than ->refresh(): Activity has a composite
        // primary key (deal_id, date_utc), which Eloquent's default
        // getKey()-based refresh()/find() can't address (see the model).
        $this->assertNull($findOpening()->open_price, 'reprocessing the same opening activity must stay open_price=NULL');
    }

    public function test_two_closes_synced_separately_still_end_with_exactly_one_opening(): void
    {
        $capital = $this->mock(CapitalApiService::class);
        $historySync = new HistorySyncService($capital);

        $capital->shouldReceive('getActivity')->once()->andReturn([
            'activities' => [$this->activityPayload('D2', '2026-06-01T10:00:00', 2000.0)],
        ]);
        $capital->shouldReceive('getTransactions')->once()->andReturn(['transactions' => []]);
        $historySync->sync(3600);

        // Later sync: the opening activity is re-fetched again (overlap)
        // *alongside* a brand-new partial close in the same batch.
        $capital->shouldReceive('getActivity')->once()->andReturn([
            'activities' => [
                $this->activityPayload('D2', '2026-06-01T10:00:00', 2000.0),
                $this->activityPayload('D2', '2026-06-01T11:00:00', 2050.0, 'SELL'),
            ],
        ]);
        $capital->shouldReceive('getTransactions')->once()->andReturn(['transactions' => []]);
        $historySync->sync(3600);

        $rows = Activity::where('deal_id', 'D2')->orderBy('date_utc')->get();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->open_price, 'earliest row must remain the opening');
        $this->assertSame(2000.0, $rows[1]->open_price, 'later row must carry the true opening level');
    }
}
