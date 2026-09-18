<?php

/**
 * Example routes for trying out the pages in this directory. Copy whatever
 * you need into your own app's routes/web.php - these aren't loaded
 * automatically by the package.
 */

use Illuminate\Support\Facades\Route;

Route::get('/voice-example', fn () => view('voice-examples.turn-based-widget'));
Route::get('/voice-realtime-example-openai', fn () => view('voice-examples.realtime-openai'));
Route::get('/voice-realtime-example-deepgram', fn () => view('voice-examples.realtime-deepgram'));
