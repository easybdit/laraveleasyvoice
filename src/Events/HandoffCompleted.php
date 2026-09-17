<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;

final class HandoffCompleted
{
    public function __construct(
        public readonly VoiceSession $session,
        public readonly string $reason = '',
    ) {
    }
}
