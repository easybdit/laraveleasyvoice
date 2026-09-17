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
            'model' => ['nullable', 'string', 'max:100'],
            'voice' => ['nullable', 'string', 'max:50'],
        ]);

        $broker = new OpenAiRealtimeTokenBroker(config('voice.realtime.openai', []));

        try {
            $result = $broker->createEphemeralToken($validated);
        } catch (ProviderException|ConnectionException $e) {
            report($e);

            return response()->json(['error' => 'The realtime provider is temporarily unavailable.'], 502);
        }

        return response()->json($result);
    }
}
