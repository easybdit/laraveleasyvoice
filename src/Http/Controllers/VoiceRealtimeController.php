<?php

namespace EasyAI\LaravelVoice\Http\Controllers;

use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Realtime\DeepgramRealtimeTokenBroker;
use EasyAI\LaravelVoice\Realtime\OpenAiRealtimeTokenBroker;
use EasyAI\LaravelVoice\Support\VoiceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VoiceRealtimeController extends Controller
{
    /**
     * Token-minting only for both providers - see config/voice.php's own
     * "realtime" docblock for why 'openai' (WebRTC) and 'deepgram' (raw
     * WebSocket + PCM) can share this endpoint's shape even though their
     * actual browser connections are completely different protocols with
     * no client built for the latter yet.
     */
    private const SUPPORTED_PROVIDERS = ['openai', 'deepgram'];

    public function token(Request $request): JsonResponse
    {
        [$userId, $guestToken] = VoiceIdentity::resolve($request);

        if ($userId === null) {
            if (! config('voice.routes.allow_guest', false)) {
                abort(403, 'A realtime token requires an authenticated user.');
            }

            $guestToken = VoiceIdentity::ensureGuestToken($guestToken);
        }

        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:100'],
            'voice' => ['nullable', 'string', 'max:50'],
            'ttl_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
        ]);

        $provider = $validated['provider'] ?? config('voice.realtime.default', 'openai');

        // Requesting an unsupported provider is a client error (422), not
        // a 502 - nothing was even attempted, so it's not a provider
        // failure.
        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return response()->json([
                'error' => "Unsupported realtime provider: {$provider}. Supported: ".implode(', ', self::SUPPORTED_PROVIDERS).'.',
            ], 422);
        }

        $broker = $provider === 'deepgram'
            ? new DeepgramRealtimeTokenBroker(config('voice.realtime.providers.deepgram', []))
            : new OpenAiRealtimeTokenBroker(config('voice.realtime.providers.openai', []));

        try {
            $result = $broker->createEphemeralToken($validated);
        } catch (ProviderException|ConnectionException $e) {
            report($e);

            return response()->json(['error' => 'The realtime provider is temporarily unavailable.'], 502);
        }

        return response()->json(array_merge($result, ['provider' => $provider]));
    }
}
