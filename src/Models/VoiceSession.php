<?php

namespace EasyAI\LaravelVoice\Models;

use EasyAI\LaravelAI\Chat\Models\ChatSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceSession extends Model
{
    protected $fillable = [
        'agent', 'provider_stt', 'provider_tts', 'provider_llm',
        'voice', 'language', 'channel', 'status', 'chat_session_id',
        'tenant_id', 'user_id', 'guest_token', 'started_at', 'ended_at',
        'metadata',
        // Usage counters - written only by VoiceAgent itself (increment()
        // already bypasses fillable for the integer columns, but
        // accumulateCost()'s update() does not), never from raw request
        // input, so including them here carries no mass-assignment risk.
        'total_stt_ms', 'total_tts_ms', 'total_prompt_tokens',
        'total_completion_tokens', 'estimated_cost',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'estimated_cost' => 'float',
    ];

    public function turns(): HasMany
    {
        return $this->hasMany(VoiceTurn::class)->orderBy('sequence');
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    /**
     * Whether the given identity owns this session. Unlike EasyAI's
     * ChatSession::isOwnedBy() (which allows access when no identity was
     * recorded, for backward compatibility with data from before it added
     * identity columns), this fails closed: a session with no matching,
     * verifiable identity is never treated as accessible. There is no
     * legacy data here to be compatible with, and voice sessions carry
     * both cost-bearing provider calls and recorded speech, so the safer
     * default is "deny unless proven owned."
     */
    public function isOwnedBy(?int $userId, ?string $guestToken, ?int $tenantId = null): bool
    {
        if ($tenantId !== null && (int) $this->tenant_id !== $tenantId) {
            return false;
        }

        if ($userId !== null) {
            return $this->user_id !== null && (int) $this->user_id === $userId;
        }

        if ($guestToken !== null && $guestToken !== '') {
            return $this->guest_token !== null && $this->guest_token === $guestToken;
        }

        return false;
    }
}
