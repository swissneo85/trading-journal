<?php

namespace Tests\Feature;

use App\Models\TradeTag;
use App\Services\TelegramUpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramUpdateHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('config')->insertOrIgnore(['key' => 'telegram_bot_token', 'value' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_callback_query_tags_trade_and_asks_for_note(): void
    {
        app(TelegramUpdateHandler::class)->handle([
            'callback_query' => [
                'id' => 'cbq1',
                'data' => 'tag:DEAL1:Gruppe A',
                'message' => ['chat' => ['id' => 555]],
            ],
        ]);

        $tag = TradeTag::find('DEAL1');
        $this->assertSame('Gruppe A', $tag->quelle);
        $this->assertSame('DEAL1', Cache::get('awaiting_note:555'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    public function test_text_message_saves_note_when_awaiting(): void
    {
        Cache::put('awaiting_note:555', 'DEAL1', now()->addMinutes(10));
        TradeTag::create(['deal_id' => 'DEAL1', 'quelle' => 'Gruppe A']);

        app(TelegramUpdateHandler::class)->handle([
            'message' => ['chat' => ['id' => 555], 'text' => 'mein Kommentar'],
        ]);

        $this->assertSame('mein Kommentar', TradeTag::find('DEAL1')->notiz);
        $this->assertNull(Cache::get('awaiting_note:555'));
    }

    public function test_text_message_ignored_when_not_awaiting_note(): void
    {
        app(TelegramUpdateHandler::class)->handle([
            'message' => ['chat' => ['id' => 999], 'text' => 'random text'],
        ]);

        Http::assertNothingSent();
    }
}
