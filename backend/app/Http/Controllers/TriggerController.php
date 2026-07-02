<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;

class TriggerController extends Controller
{
    public function poll()
    {
        Artisan::call('trading:poll-positions');

        return response()->json(['message' => Artisan::output() ?: '✅ Poll ausgelöst']);
    }

    public function history()
    {
        Artisan::call('trading:fetch-history');

        return response()->json(['message' => Artisan::output() ?: '✅ History-Sync ausgelöst']);
    }
}
