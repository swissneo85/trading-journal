<?php

use App\Models\ConfigEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('archived')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        // One-time migration of the old comma-separated `quellen` config value
        // into individual rows; the config entry itself is left untouched.
        $names = array_values(array_filter(array_map(
            'trim', explode(',', ConfigEntry::find('quellen')?->value ?? '')
        )));

        foreach ($names as $name) {
            DB::table('sources')->insert([
                'name' => $name,
                'archived' => false,
                'created_at' => now()->toIso8601String(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
