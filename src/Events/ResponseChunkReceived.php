<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Models\VoiceTurn;

/**
 * Fired for each partial chunk of the assistant's text response, only
 * when the agent has streamResponses() enabled. $type mirrors
 * LaravelEasyAI's own stream() callback shape - 'content' for the reply
 * itself, 'thinking' for a reasoning-capable model's chain-of-thought
 * tokens (Ollama/Gemini/Anthropic).
 */
final class ResponseChunkReceived
{
    public function __construct(
        public readonly VoiceSession $session,
        public readonly VoiceTurn $turn,
        public readonly string $chunk,
        public readonly string $type = 'content',
    ) {
    }
}
