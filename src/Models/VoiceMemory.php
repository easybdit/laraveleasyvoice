<?php

namespace EasyAI\LaravelVoice\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A fact remembered about a specific caller (by tenant/user/guest-token
 * identity, matching VoiceSession's own identity columns) - not tied to
 * any one session, so it survives across separate calls. Deliberately
 * a flat key/value row, not an embedding store - see the migration's
 * own docblock for scope.
 */
class VoiceMemory extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'guest_token', 'key', 'value'];

    public static function remember(VoiceSession $session, string $key, string $value): self
    {
        return self::updateOrCreate(
            array_merge(self::identityFor($session), ['key' => $key]),
            ['value' => $value],
        );
    }

    public static function recall(VoiceSession $session, string $key): ?string
    {
        return self::query()
            ->where(self::identityFor($session))
            ->where('key', $key)
            ->value('value');
    }

    /**
     * A session with no identity at all (no tenant, no user, no guest
     * token) has nothing to scope memory to - remember()/recall() would
     * otherwise silently share one global row across every anonymous
     * caller with the same key, which is worse than not remembering
     * anything.
     */
    public static function hasIdentity(VoiceSession $session): bool
    {
        return $session->tenant_id !== null || $session->user_id !== null || $session->guest_token !== null;
    }

    private static function identityFor(VoiceSession $session): array
    {
        return [
            'tenant_id' => $session->tenant_id,
            'user_id' => $session->user_id,
            'guest_token' => $session->guest_token,
        ];
    }
}
