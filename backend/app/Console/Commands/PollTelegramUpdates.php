<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ConfigEntry;
use App\Services\TelegramService;
use App\Services\TelegramUpdateHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('trading:poll-telegram')]
#[Description('Pollt Telegram-Updates (getUpdates) und verarbeitet Callback/Notiz-Nachrichten')]
class PollTelegramUpdates extends Command
{
    public function handle(TelegramService $telegram, TelegramUpdateHandler $handler): int
    {
        $offset = (int) (ConfigEntry::find('telegram_update_offset')?->value ?? 0);

        try {
            $updates = $telegram->getUpdates($offset);
        } catch (Throwable $e) {
            Log::warning('PollTelegramUpdates: '.$e->getMessage());
            ActivityLog::log('error', 'PollTelegramUpdates: '.$e->getMessage());

            return self::SUCCESS;
        }

        foreach ($updates as $update) {
            $handler->handle($update);
            $offset = $update['update_id'] + 1;
        }

        if (! empty($updates)) {
            ConfigEntry::updateOrCreate(['key' => 'telegram_update_offset'], ['value' => (string) $offset]);
            ActivityLog::log('telegram', count($updates).' Telegram Update(s) verarbeitet');
        }

        return self::SUCCESS;
    }
}
