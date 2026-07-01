<?php

namespace Database\Seeders;

use App\Models\ConfigEntry;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
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
            ConfigEntry::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
