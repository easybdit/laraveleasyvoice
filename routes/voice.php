<?php

use EasyAI\LaravelVoice\Http\Controllers\VoiceSessionController;
use EasyAI\LaravelVoice\Http\Controllers\VoiceTurnController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('voice.routes.prefix', 'voice'))->name('voice.')->group(function () {
    Route::post('sessions', [VoiceSessionController::class, 'store'])->name('sessions.store');
    Route::post('sessions/{session}/end', [VoiceSessionController::class, 'end'])->name('sessions.end');

    Route::post('sessions/{session}/turns', [VoiceTurnController::class, 'store'])->name('sessions.turns.store');
    Route::get('sessions/{session}/turns/{turn}/audio', [VoiceTurnController::class, 'audio'])->name('turns.audio');
});
