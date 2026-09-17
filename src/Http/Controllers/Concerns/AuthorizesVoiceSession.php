<?php

namespace EasyAI\LaravelVoice\Http\Controllers\Concerns;

use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Support\VoiceIdentity;
use Illuminate\Http\Request;

trait AuthorizesVoiceSession
{
    /**
     * @return array{0: int|null, 1: string|null} [$userId, $guestToken]
     */
    protected function authorizeVoiceSession(Request $request, VoiceSession $session): array
    {
        [$userId, $guestToken] = VoiceIdentity::resolve($request);

        if (! $session->isOwnedBy($userId, $guestToken)) {
            abort(403, 'You do not have access to this voice session.');
        }

        return [$userId, $guestToken];
    }
}
