<?php

namespace EasyAI\LaravelVoice\Managers;

use EasyAI\LaravelVoice\Providers\Stt\OpenAiSttProvider;
use Illuminate\Support\Manager;

class SpeechToTextManager extends Manager
{
    public function getDefaultDriver()
    {
        return $this->config->get('voice.stt.default', 'openai');
    }

    protected function createOpenaiDriver(): OpenAiSttProvider
    {
        return new OpenAiSttProvider($this->config->get('voice.stt.providers.openai', []));
    }
}
