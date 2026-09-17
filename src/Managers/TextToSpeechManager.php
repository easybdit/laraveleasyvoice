<?php

namespace EasyAI\LaravelVoice\Managers;

use EasyAI\LaravelVoice\Providers\Tts\ElevenLabsTtsProvider;
use EasyAI\LaravelVoice\Providers\Tts\OpenAiTtsProvider;
use Illuminate\Support\Manager;

class TextToSpeechManager extends Manager
{
    public function getDefaultDriver()
    {
        return $this->config->get('voice.tts.default', 'openai');
    }

    protected function createOpenaiDriver(): OpenAiTtsProvider
    {
        return new OpenAiTtsProvider($this->config->get('voice.tts.providers.openai', []));
    }

    protected function createElevenlabsDriver(): ElevenLabsTtsProvider
    {
        return new ElevenLabsTtsProvider($this->config->get('voice.tts.providers.elevenlabs', []));
    }
}
