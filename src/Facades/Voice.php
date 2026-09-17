<?php

namespace EasyAI\LaravelVoice\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \EasyAI\LaravelVoice\Contracts\SpeechToTextProvider stt(?string $driver = null)
 * @method static \EasyAI\LaravelVoice\Contracts\TextToSpeechProvider tts(?string $driver = null)
 * @method static void registerAgent(string $name, \Closure $factory)
 * @method static \EasyAI\LaravelVoice\Agent\VoiceAgent agent(string $name)
 *
 * @see \EasyAI\LaravelVoice\VoiceManager
 */
class Voice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-voice';
    }
}
