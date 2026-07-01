<?php

namespace App\Http\Controllers;

use App\Models\ConfigEntry;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const MASK = '●●●●●●';

    private const SENSITIVE_KEYS = [
        'capital_api_key',
        'capital_password',
        'telegram_bot_token',
    ];

    public function index()
    {
        $values = ConfigEntry::pluck('value', 'key');

        foreach (self::SENSITIVE_KEYS as $key) {
            $values[$key] = filled($values[$key] ?? null) ? self::MASK : '';
        }

        return response()->json($values);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'quellen' => 'sometimes|nullable|string',
            'capital_api_key' => 'sometimes|nullable|string',
            'capital_email' => 'sometimes|nullable|email',
            'capital_password' => 'sometimes|nullable|string',
            'capital_url' => 'sometimes|nullable|url',
            'poll_interval_seconds' => 'sometimes|nullable|numeric|min:30',
            'telegram_bot_token' => 'sometimes|nullable|string',
            'telegram_chat_id' => 'sometimes|nullable|string',
        ]);

        foreach ($validated as $key => $value) {
            // Frontend never sends back the mask placeholder for an
            // intentional change — treat it as "field left untouched".
            if (in_array($key, self::SENSITIVE_KEYS, true) && $value === self::MASK) {
                continue;
            }

            ConfigEntry::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }

        return response()->json(['message' => '✅ Einstellungen gespeichert']);
    }
}
