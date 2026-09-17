<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;

final class ToolCallCompleted
{
    public function __construct(
        public readonly VoiceSession $session,
        public readonly string $tool,
        public readonly mixed $result,
        public readonly ?string $tier = null,
    ) {
    }
}
