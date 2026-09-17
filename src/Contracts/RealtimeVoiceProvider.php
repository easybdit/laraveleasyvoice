<?php

namespace EasyAI\LaravelVoice\Contracts;

use EasyAI\LaravelVoice\Models\VoiceSession;

/**
 * DESIGN-STAGE CONTRACT - NOT YET IMPLEMENTED.
 *
 * No class in this package implements this interface. It exists so a
 * future realtime provider (OpenAI Realtime, a WebRTC-based provider, a
 * custom WebSocket provider) has a stable shape to target, without this
 * package building actual realtime transport infrastructure before an
 * architecture decision is made for how Laravel hosts a long-lived
 * duplex connection at all - PHP-FPM's request/response model cannot
 * hold one open. That decision (Octane+Reverb doing the relay in-process,
 * a small external relay service, or exposing the provider's own
 * realtime endpoint to the browser with this package only minting a
 * short-lived token) is an infrastructure choice for whoever adopts
 * this, not something this contract can or should presume.
 *
 * connect() is expected to be synchronous only in the sense of handing
 * back a RealtimeConnection immediately - the connection itself is
 * long-lived and event-driven via the callbacks below, not something a
 * single PHP request lifecycle can hold open on its own.
 */
interface RealtimeVoiceProvider
{
    public function connect(VoiceSession $session, array $options = []): RealtimeConnection;
}
