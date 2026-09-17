<?php

namespace EasyAI\LaravelVoice\Events;

use EasyAI\LaravelVoice\Models\VoiceSession;

/**
 * Fired when the agent (or a tool it called) decides it cannot resolve
 * the caller's request and a human should take over. Carries no
 * transport of its own - a listener is expected to notify a human
 * through whatever channel the host app already uses (Slack, a support
 * queue, a telephony transfer once that exists) and then call
 * VoiceAgent::completeHandoff().
 */
final class HandoffRequested
{
    public function __construct(
        public readonly VoiceSession $session,
        public readonly string $reason = '',
    ) {
    }
}
