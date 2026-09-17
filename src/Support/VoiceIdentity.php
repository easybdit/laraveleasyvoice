<?php

namespace EasyAI\LaravelVoice\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Resolves "who is calling" for a voice HTTP request - an authenticated
 * user id, or (only when explicitly enabled) a long-lived signed guest
 * cookie. Deliberately its own class rather than reusing LaravelEasyAI's
 * ChatIdentity: that class is keyed off config('ai.chat.*'), and coupling
 * Voice's access policy to the text-chat widget's config would let a
 * change meant for one silently affect the other. Guest access defaults
 * to off here (LaravelEasyAI's chat defaults it on) because a voice
 * request is more expensive per call than a text one.
 */
class VoiceIdentity
{
    public const COOKIE_NAME = 'laravel_voice_guest';

    /**
     * @return array{0: int|null, 1: string|null} [$userId, $guestToken]
     */
    public static function resolve(Request $request): array
    {
        $userId = self::resolveUserId($request);

        if ($userId) {
            return [$userId, null];
        }

        if (! config('voice.routes.allow_guest', false)) {
            return [null, null];
        }

        $token = $request->cookie(self::COOKIE_NAME);

        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            $token = null;
        }

        return [null, $token];
    }

    private static function resolveUserId(Request $request): ?int
    {
        $resolver = config('voice.routes.identity_resolver');

        if ($resolver && is_callable($resolver)) {
            try {
                $resolved = $resolver($request);
            } catch (\Throwable) {
                return null;
            }

            return $resolved !== null && $resolved !== '' ? (int) $resolved : null;
        }

        return $request->user()?->getAuthIdentifier()
            ? (int) $request->user()->getAuthIdentifier()
            : null;
    }

    /**
     * No default fallback here (unlike resolveUserId's $request->user()
     * fallback) - there is no single Laravel convention for "current
     * tenant" the way there is for "current user". Returns null unless
     * voice.routes.tenant_resolver is explicitly configured.
     */
    public static function resolveTenant(Request $request): ?int
    {
        $resolver = config('voice.routes.tenant_resolver');

        if (! $resolver || ! is_callable($resolver)) {
            return null;
        }

        try {
            $resolved = $resolver($request);
        } catch (\Throwable) {
            return null;
        }

        return $resolved !== null && $resolved !== '' ? (int) $resolved : null;
    }

    public static function ensureGuestToken(?string $existing): string
    {
        if ($existing) {
            return $existing;
        }

        $token = Str::random(40);

        Cookie::queue(Cookie::make(
            self::COOKIE_NAME,
            $token,
            60 * 24 * 365,
            null,
            null,
            null,
            true,   // httpOnly
            false,
            'lax'
        ));

        return $token;
    }

    public static function rateLimitKey(?int $userId, ?string $guestToken): string
    {
        return $userId ? "u:{$userId}" : 'g:'.($guestToken ?: 'anon');
    }
}
