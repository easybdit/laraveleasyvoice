<?php

namespace EasyAI\LaravelVoice\Http\Controllers\Concerns;

use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Support\VoiceIdentity;
use Illuminate\Http\Request;

trait AuthorizesVoiceSession
{
    /**
     * @return array{0: int|null, 1: string|null, 2: int|null} [$userId, $guestToken, $tenantId]
     */
    protected function authorizeVoiceSession(Request $request, VoiceSession $session): array
    {
        [$userId, $guestToken] = VoiceIdentity::resolve($request);
        $tenantId = VoiceIdentity::resolveTenant($request);

        if (! $session->isOwnedBy($userId, $guestToken, $tenantId)) {
            abort(403, 'You do not have access to this voice session.');
        }

        return [$userId, $guestToken, $tenantId];
    }
}
