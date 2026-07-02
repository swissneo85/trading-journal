<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollTelegramUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_updates_and_persists_offset(): void
    {
        DB::table('config')->insertOrIgnore(['key' => 'telegram_bot_token', 'value' => 'test-token']);
        DB::table('config')->insertOrIgnore(['key' => 'telegram_update_offset', 'value' => '0']);

        Http::fake([
            'api.telegram.org/*/getUpdates*' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 42, 'message' => ['chat' => ['id' => 1], 'text' => 'irrelevant']],
            ]]),
        ]);

        $this->artisan('trading:poll-telegram')->assertSuccessful();

        $this->assertSame('43', DB::table('config')->where('key', 'telegram_update_offset')->value('value'));
        $this->assertTrue(ActivityLog::where('type', 'telegram')->exists());
    }

    public function test_no_updates_leaves_offset_untouched(): void
    {
        DB::table('config')->insertOrIgnore(['key' => 'telegram_bot_token', 'value' => 'test-token']);
        DB::table('config')->insertOrIgnore(['key' => 'telegram_update_offset', 'value' => '7']);

        Http::fake([
            'api.telegram.org/*/getUpdates*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('trading:poll-telegram')->assertSuccessful();

        $this->assertSame('7', DB::table('config')->where('key', 'telegram_update_offset')->value('value'));
        $this->assertFalse(ActivityLog::where('type', 'telegram')->exists());
    }
}
