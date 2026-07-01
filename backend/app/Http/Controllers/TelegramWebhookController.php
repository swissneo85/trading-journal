<?php

namespace App\Http\Controllers;

use App\Models\TradeTag;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request, TelegramService $telegram)
    {
        $payload = $request->all();

        try {
            if ($callback = $payload['callback_query'] ?? null) {
                $this->handleCallback($callback, $telegram);
            } elseif ($message = $payload['message'] ?? null) {
                $this->handleMessage($message, $telegram);
            }
        } catch (Throwable $e) {
            Log::warning('TelegramWebhookController: '.$e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    private function handleCallback(array $callback, TelegramService $telegram): void
    {
        $data = $callback['data'] ?? '';
        $chatId = $callback['message']['chat']['id'] ?? null;

        [$prefix, $dealId, $quelle] = array_pad(explode(':', $data, 3), 3, null);

        if ($prefix === 'tag' && $dealId) {
            TradeTag::updateOrCreate(
                ['deal_id' => $dealId],
                ['quelle' => $quelle, 'tagged_at' => now()->toIso8601String()]
            );

            if ($chatId !== null) {
                Cache::put('awaiting_note:'.$chatId, $dealId, now()->addMinutes(10));
            }

            $telegram->sendMessage('Notiz? (oder /skip)', null, $chatId);
        }

        $telegram->answerCallbackQuery($callback['id']);
    }

    private function handleMessage(array $message, TelegramService $telegram): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');

        if ($chatId === null) {
            return;
        }

        $dealId = Cache::get('awaiting_note:'.$chatId);
        if (! $dealId) {
            return;
        }

        Cache::forget('awaiting_note:'.$chatId);

        if ($text !== '/skip') {
            TradeTag::updateOrCreate(['deal_id' => $dealId], ['notiz' => $text]);
        }

        $telegram->sendMessage('✅ Notiz gespeichert', null, $chatId);
    }
}
