<?php

use EasyAI\LaravelVoice\Http\Controllers\VoiceRealtimeController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('voice.routes.prefix', 'voice'))->name('voice.')->group(function () {
    Route::post('realtime/token', [VoiceRealtimeController::class, 'token'])->name('realtime.token');
});
