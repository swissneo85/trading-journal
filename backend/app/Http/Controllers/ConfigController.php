<?php

namespace App\Http\Controllers;

use App\Models\ConfigEntry;

class ConfigController extends Controller
{
    // Credentials live in the same `config` table but must never be exposed
    // via this general-purpose endpoint (used by the frontend e.g. for the
    // "quellen" tag dropdown) — the masked Settings endpoint handles those.
    private const HIDDEN_KEYS = [
        'capital_api_key',
        'capital_password',
        'telegram_bot_token',
    ];

    public function index()
    {
        $values = ConfigEntry::pluck('value', 'key')->except(self::HIDDEN_KEYS);

        return response()->json($values);
    }
}
