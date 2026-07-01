<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TradeController;
use Illuminate\Support\Facades\Route;

Route::prefix('trading')->group(function () {
    Route::get('/trades', [TradeController::class, 'index']);
    Route::get('/detail', [TradeController::class, 'detail']);
    Route::post('/tag', [TradeController::class, 'tag']);
    Route::get('/config', [ConfigController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::post('/import', [ImportController::class, 'store']);
    Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
});
