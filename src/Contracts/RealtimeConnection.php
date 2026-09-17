<?php

namespace EasyAI\LaravelVoice\Contracts;

/**
 * DESIGN-STAGE CONTRACT - NOT YET IMPLEMENTED. See RealtimeVoiceProvider's
 * own docblock for why.
 *
 * A single live realtime session with a provider. Every on*() registers
 * a callback invoked as events arrive from the provider - this
 * interface does not prescribe how those events actually reach PHP
 * (a WebSocket client loop, a webhook, a queue worker), since that is
 * exactly the transport decision left open above.
 */
interface RealtimeConnection
{
    /** Sends one chunk of the caller's microphone audio to the provider. */
    public function sendAudioChunk(string $chunk): void;

    /** @param  callable(string $text, bool $isFinal): void  $callback */
    public function onTranscript(callable $callback): void;

    /** @param  callable(string $audioChunk): void  $callback */
    public function onResponseAudioChunk(callable $callback): void;

    /** @param  callable(\Throwable $exception): void  $callback */
    public function onError(callable $callback): void;

    /**
     * Stops the provider's current response mid-generation - the
     * server-side half of barge-in. Whether/how this is possible is
     * entirely provider-specific; a provider that cannot cancel
     * mid-response should throw rather than silently no-op.
     */
    public function interrupt(): void;

    public function close(): void;
}
