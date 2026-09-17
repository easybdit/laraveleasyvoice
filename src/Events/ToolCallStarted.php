<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;

final class ToolCallStarted
{
    public function __construct(
        public readonly VoiceSession $session,
        public readonly string $tool,
        public readonly array $arguments,
        public readonly ?string $tier = null,
    ) {
    }
}
