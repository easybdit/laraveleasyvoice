<?php

namespace EasyAI\LaravelVoice\Http\Controllers;

use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Realtime\OpenAiRealtimeTokenBroker;
use EasyAI\LaravelVoice\Support\VoiceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VoiceRealtimeController extends Controller
{
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
        ]);

        $provider = $validated['provider'] ?? config('voice.realtime.default', 'openai');

        // Only 'openai' exists today - see config/voice.php's own
        // "realtime" docblock for exactly what a second provider would
        // need before it could be added here. Requesting an unsupported
        // one is a client error (422), not a 502 - nothing was even
        // attempted, so it's not a provider failure.
        if ($provider !== 'openai') {
            return response()->json([
                'error' => "Unsupported realtime provider: {$provider}. Only 'openai' is implemented today.",
            ], 422);
        }

        $broker = new OpenAiRealtimeTokenBroker(config('voice.realtime.providers.openai', []));

        try {
            $result = $broker->createEphemeralToken($validated);
        } catch (ProviderException|ConnectionException $e) {
            report($e);

            return response()->json(['error' => 'The realtime provider is temporarily unavailable.'], 502);
        }

        return response()->json(array_merge($result, ['provider' => $provider]));
    }
}
