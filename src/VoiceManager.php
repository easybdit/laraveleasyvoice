<?php

namespace EasyAI\LaravelVoice;

use EasyAI\LaravelVoice\Agent\VoiceAgent;
use EasyAI\LaravelVoice\Contracts\SpeechToTextProvider;
use EasyAI\LaravelVoice\Contracts\TextToSpeechProvider;
use EasyAI\LaravelVoice\Managers\SpeechToTextManager;
use EasyAI\LaravelVoice\Managers\TextToSpeechManager;

class VoiceManager
{
    /** @var array<string, \Closure> */
    protected array $agentFactories = [];

    public function __construct(
        protected SpeechToTextManager $sttManager,
        protected TextToSpeechManager $ttsManager,
    ) {
    }

    public function stt(?string $driver = null): SpeechToTextProvider
    {
        return $this->sttManager->driver($driver);
    }

    public function tts(?string $driver = null): TextToSpeechProvider
    {
        return $this->ttsManager->driver($driver);
    }

    /**
     * Registers a named agent. The factory configures a fresh VoiceAgent
     * builder (stt/tts/llm drivers, tools, system prompt, limits). This is
     * a runtime registration - call it from a service provider's boot(),
     * the same way you'd call Gate::define() or Route::get() - never from
     * config/voice.php, since tool handlers are closures and config files
     * get var_export()'d by `config:cache`, which cannot serialize a
     * Closure.
     */
    public function registerAgent(string $name, \Closure $factory): void
    {
        $this->agentFactories[$name] = $factory;
    }

    public function agent(string $name): VoiceAgent
    {
        if (! isset($this->agentFactories[$name])) {
            throw new \InvalidArgumentException("Voice agent [{$name}] is not registered. Register it with Voice::registerAgent().");
        }

        $agent = new VoiceAgent($name, $this);

        ($this->agentFactories[$name])($agent);

        return $agent;
    }
}
