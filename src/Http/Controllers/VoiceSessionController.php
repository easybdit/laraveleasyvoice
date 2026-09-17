<?php

namespace EasyAI\LaravelVoice\Http\Controllers;

use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Http\Controllers\Concerns\AuthorizesVoiceSession;
use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Support\VoiceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VoiceSessionController extends Controller
{
    use AuthorizesVoiceSession;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent' => ['required', 'string', 'max:100'],
            'language' => ['nullable', 'string', 'max:10'],
            'channel' => ['nullable', 'string', 'max:20'],
        ]);

        [$userId, $guestToken] = VoiceIdentity::resolve($request);
        $tenantId = VoiceIdentity::resolveTenant($request);

        if ($userId === null) {
            if (! config('voice.routes.allow_guest', false)) {
                abort(403, 'A voice session requires an authenticated user.');
            }

            // First-time guest: resolve() found no cookie yet, so one is
            // minted now rather than treating "no cookie yet" as "denied".
            $guestToken = VoiceIdentity::ensureGuestToken($guestToken);
        }

        try {
            $agent = Voice::agent($validated['agent']);
        } catch (\InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        $session = $agent->startSession([
            'user_id' => $userId,
            'guest_token' => $guestToken,
            'tenant_id' => $tenantId,
            'language' => $validated['language'] ?? null,
            'channel' => $validated['channel'] ?? 'web',
        ]);

        return response()->json([
            'id' => $session->id,
            'agent' => $session->agent,
            'status' => $session->status,
        ], 201);
    }

    public function end(Request $request, VoiceSession $session): JsonResponse
    {
        $this->authorizeVoiceSession($request, $session);

        Voice::agent($session->agent)->endSession($session);

        return response()->json([
            'id' => $session->id,
            'status' => $session->fresh()->status,
        ]);
    }
}
