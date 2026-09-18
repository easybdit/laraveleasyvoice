<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Models\VoiceTurn;

/**
 * Fired when a stale `pending` turn (its owning process gone - a crash,
 * a timeout, a killed worker) is reclaimed via a matching Idempotency-Key
 * retry, immediately after the atomic reclaim succeeds and before the
 * new attempt actually runs. $stage identifies which portion is about to
 * be redone - 'stt' (the user turn itself was stuck) or 'llm' (the user
 * turn already completed; its assistant turn is what was stuck, so only
 * the LLM/tool/TTS portion resumes).
 */
final class TurnRecovered
{
    public function __construct(
        public readonly VoiceSession $session,
        public readonly VoiceTurn $turn,
        public readonly string $stage,
    ) {
    }
}
