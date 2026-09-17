<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;

final class VoiceError
{
    public function __construct(
        public readonly VoiceSession $session,
        public readonly string $stage,
        public readonly \Throwable $exception,
    ) {
    }
}
