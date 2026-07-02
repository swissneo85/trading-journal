<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * install.sh runs this on every deployment, so defaults must only ever
     * fill in missing keys — an existing value (e.g. user-entered API
     * credentials) must never be touched.
     */
    public function run(): void
    {
        $defaults = [
            'quellen' => 'Gruppe A,Gruppe B,Gruppe C,Eigene Idee',
            'capital_api_key' => '',
            'capital_email' => '',
            'capital_password' => '',
            'capital_url' => 'https://api-capital.backend-capital.com',
            'poll_interval_seconds' => '60',
            'telegram_bot_token' => '',
            'telegram_chat_id' => '',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('config')->insertOrIgnore(['key' => $key, 'value' => $value]);
        }
    }
}
