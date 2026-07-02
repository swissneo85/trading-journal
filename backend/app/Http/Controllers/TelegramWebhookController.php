<?php

namespace App\Http\Controllers;

use App\Services\TelegramUpdateHandler;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request, TelegramUpdateHandler $handler)
    {
        $handler->handle($request->all());

        return response()->json(['ok' => true]);
    }
}
