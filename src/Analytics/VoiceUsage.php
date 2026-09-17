<?php

namespace EasyAI\LaravelVoice\Analytics;

use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Models\VoiceTurn;
use Illuminate\Database\Eloquent\Builder;

/**
 * A thin query layer over voice_sessions/voice_turns - deliberately not a
 * separate rollup/aggregate table. Every figure here is computed on read
 * from the same rows VoiceAgent already writes; add a materialized
 * summary table later only if a real query-performance problem shows up,
 * not before. Exposed so a future EasyAI API/SaaS layer (or your own
 * admin dashboard) can consume usage data without querying the schema
 * directly.
 */
class VoiceUsage
{
    /**
     * Starting point for a custom query - filter/aggregate further with
     * normal Eloquent, e.g. VoiceUsage::query()->where('agent', 'receptionist').
     */
    public static function query(): Builder
    {
        return VoiceSession::query();
    }

    /**
     * Aggregate usage across sessions matching the given filters.
     * $filters is a plain column => value map applied with where() -
     * e.g. ['agent' => 'receptionist', 'tenant_id' => 42].
     */
    public static function summary(?Builder $query = null, array $filters = []): array
    {
        $query ??= self::query();

        foreach ($filters as $column => $value) {
            $query->where($column, $value);
        }

        $sessionIds = (clone $query)->pluck('id');

        return [
            'sessions' => $sessionIds->count(),
            'active_sessions' => (clone $query)->where('status', 'active')->count(),
            'ended_sessions' => (clone $query)->where('status', 'ended')->count(),
            'failed_sessions' => (clone $query)->where('status', 'failed')->count(),
            'total_stt_ms' => (int) (clone $query)->sum('total_stt_ms'),
            'total_tts_ms' => (int) (clone $query)->sum('total_tts_ms'),
            'total_prompt_tokens' => (int) (clone $query)->sum('total_prompt_tokens'),
            'total_completion_tokens' => (int) (clone $query)->sum('total_completion_tokens'),
            'estimated_cost' => (float) (clone $query)->sum('estimated_cost'),
            'turns' => VoiceTurn::whereIn('voice_session_id', $sessionIds)->count(),
            'failed_turns' => VoiceTurn::whereIn('voice_session_id', $sessionIds)->where('status', 'failed')->count(),
            'avg_turn_latency_ms' => (int) round(
                VoiceTurn::whereIn('voice_session_id', $sessionIds)
                    ->where('speaker', 'assistant')
                    ->whereNotNull('latency_ms')
                    ->avg('latency_ms') ?? 0
            ),
        ];
    }

    /**
     * Usage/timing for a single session - the per-call detail view a
     * "session detail" admin page would show.
     */
    public static function forSession(VoiceSession $session): array
    {
        $turns = $session->turns();

        return [
            'session_id' => $session->id,
            'agent' => $session->agent,
            'status' => $session->status,
            'turns' => (clone $turns)->count(),
            'user_turns' => (clone $turns)->where('speaker', 'user')->count(),
            'assistant_turns' => (clone $turns)->where('speaker', 'assistant')->count(),
            'failed_turns' => (clone $turns)->where('status', 'failed')->count(),
            'total_stt_ms' => $session->total_stt_ms,
            'total_tts_ms' => $session->total_tts_ms,
            'total_prompt_tokens' => $session->total_prompt_tokens,
            'total_completion_tokens' => $session->total_completion_tokens,
            'estimated_cost' => $session->estimated_cost,
            'duration_seconds' => self::durationSeconds($session),
            'avg_assistant_latency_ms' => (int) round(
                (clone $turns)->where('speaker', 'assistant')->whereNotNull('latency_ms')->avg('latency_ms') ?? 0
            ),
        ];
    }

    private static function durationSeconds(VoiceSession $session): ?int
    {
        if ($session->started_at === null) {
            return null;
        }

        $end = $session->ended_at ?? now();

        return $session->started_at->diffInSeconds($end);
    }
}
