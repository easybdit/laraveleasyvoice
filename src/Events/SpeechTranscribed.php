<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Models\VoiceTurn;

final class SpeechTranscribed
{
    public function __construct(
        public readonly VoiceSession $session,
        public readonly VoiceTurn $turn,
    ) {
    }
}
