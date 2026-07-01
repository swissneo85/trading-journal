<?php

namespace App\Console\Commands;

use App\Models\ConfigEntry;
use App\Services\CapitalApiService;
use App\Services\TelegramService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('trading:poll-positions')]
#[Description('Pollt offene Positionen von Capital.com und benachrichtigt bei neuen via Telegram')]
class PollCapitalPositions extends Command
{
    public function handle(CapitalApiService $capital, TelegramService $telegram): int
    {
        $intervalSeconds = (int) (ConfigEntry::find('poll_interval_seconds')?->value ?? 60);

        if (time() - Cache::get('last_poll_run', 0) < $intervalSeconds) {
            return self::SUCCESS;
        }
        Cache::put('last_poll_run', time());

        try {
            $positions = collect($capital->getPositions()['positions'] ?? []);
        } catch (Throwable $e) {
            Log::warning('PollCapitalPositions: '.$e->getMessage());

            return self::SUCCESS;
        }

        $entriesByDealId = $positions->keyBy(fn (array $entry) => $entry['position']['dealId'] ?? null);
        $currentIds = $entriesByDealId->keys()->filter()->values()->all();
        $knownIds = Cache::get('known_deal_ids', []);
        $newIds = array_diff($currentIds, $knownIds);

        if ($newIds) {
            $quellen = array_values(array_filter(array_map('trim', explode(
                ',', ConfigEntry::find('quellen')?->value ?? ''
            ))));

            foreach ($newIds as $dealId) {
                $entry = $entriesByDealId->get($dealId);
                if (! $entry) {
                    continue;
                }

                $this->notifyNewPosition($telegram, $dealId, $entry, $quellen);
            }
        }

        Cache::put('known_deal_ids', $currentIds, now()->addDays(7));

        return self::SUCCESS;
    }

    private function notifyNewPosition(TelegramService $telegram, string $dealId, array $entry, array $quellen): void
    {
        $position = $entry['position'] ?? [];
        $market = $entry['market'] ?? [];
        $instrument = $market['instrumentName'] ?? $position['epic'] ?? '?';

        $text = "Neue Position: {$instrument} {$position['direction']} {$position['size']} Lots @ {$position['level']}\n\nQuelle?";

        $buttons = collect($quellen)->map(fn (string $quelle) => [[
            'text' => $quelle,
            'callback_data' => "tag:{$dealId}:{$quelle}",
        ]])->values()->all();

        try {
            $telegram->sendMessage($text, $buttons);
        } catch (Throwable $e) {
            Log::warning('PollCapitalPositions: Telegram-Versand fehlgeschlagen: '.$e->getMessage());
        }
    }
}
