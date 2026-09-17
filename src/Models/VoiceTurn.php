<?php

namespace EasyAI\LaravelVoice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceTurn extends Model
{
    protected $fillable = [
        'voice_session_id', 'sequence', 'speaker', 'transcript',
        'audio_path', 'audio_duration_ms', 'tool_calls', 'latency_ms',
        'status', 'error_message',
    ];

    protected $casts = [
        'tool_calls' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class, 'voice_session_id');
    }
}
