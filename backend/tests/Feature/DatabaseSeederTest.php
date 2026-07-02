<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_fills_in_missing_defaults(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('', DB::table('config')->where('key', 'capital_api_key')->value('value'));
        $this->assertSame('60', DB::table('config')->where('key', 'poll_interval_seconds')->value('value'));
    }

    public function test_reseeding_never_overwrites_user_entered_values(): void
    {
        $this->seed(DatabaseSeeder::class);

        DB::table('config')->where('key', 'capital_api_key')->update(['value' => 'user-secret-key']);
        DB::table('config')->where('key', 'telegram_chat_id')->update(['value' => '123456']);

        // Simulate install.sh running db:seed again on redeploy.
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('user-secret-key', DB::table('config')->where('key', 'capital_api_key')->value('value'));
        $this->assertSame('123456', DB::table('config')->where('key', 'telegram_chat_id')->value('value'));
    }
}
