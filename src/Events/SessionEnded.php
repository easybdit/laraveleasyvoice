<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;

final class SessionEnded
{
    public function __construct(public readonly VoiceSession $session)
    {
    }
}
