<?php

namespace App\Services;

use App\Models\ConfigEntry;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramService
{
    private ?string $botToken;

    private ?string $chatId;

    public function __construct()
    {
        $this->botToken = ConfigEntry::find('telegram_bot_token')?->value;
        $this->chatId = ConfigEntry::find('telegram_chat_id')?->value;
    }

    public function sendMessage(string $text, ?array $inlineKeyboard = null, ?string $chatId = null): array
    {
        if (empty($this->botToken)) {
            throw new RuntimeException('Telegram Bot-Token fehlt — bitte im Settings-Tab eintragen');
        }

        $payload = [
            'chat_id' => $chatId ?? $this->chatId,
            'text' => $text,
        ];

        if ($inlineKeyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        return Http::asForm()
            ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload)
            ->json();
    }

    public function answerCallbackQuery(string $callbackQueryId): array
    {
        if (empty($this->botToken)) {
            throw new RuntimeException('Telegram Bot-Token fehlt — bitte im Settings-Tab eintragen');
        }

        return Http::asForm()
            ->post("https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
            ])
            ->json();
    }

    public function getUpdates(int $offset = 0): array
    {
        if (empty($this->botToken)) {
            return [];
        }

        $response = Http::get("https://api.telegram.org/bot{$this->botToken}/getUpdates", [
            'offset' => $offset,
            'timeout' => 0, // kurzes Polling reicht, laeuft eh jede Minute
        ]);

        return $response->json('result', []);
    }
}
