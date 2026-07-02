<?php

namespace App\Services;

use App\Models\TradeTag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramUpdateHandler
{
    public function __construct(private TelegramService $telegram) {}

    public function handle(array $update): void
    {
        try {
            if ($callback = $update['callback_query'] ?? null) {
                $this->handleCallback($callback);
            } elseif ($message = $update['message'] ?? null) {
                $this->handleMessage($message);
            }
        } catch (Throwable $e) {
            Log::warning('TelegramUpdateHandler: '.$e->getMessage());
        }
    }

    private function handleCallback(array $callback): void
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

            $this->telegram->sendMessage('Notiz? (oder /skip)', null, $chatId);
        }

        $this->telegram->answerCallbackQuery($callback['id']);
    }

    private function handleMessage(array $message): void
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

        $this->telegram->sendMessage('✅ Notiz gespeichert', null, $chatId);
    }
}
