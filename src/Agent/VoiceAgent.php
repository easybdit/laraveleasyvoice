<?php

namespace EasyAI\LaravelVoice\Agent;

use EasyAI\LaravelAI\Chat\Models\ChatMessage;
use EasyAI\LaravelAI\Facades\AI;
use EasyAI\LaravelVoice\Events\HandoffCompleted;
use EasyAI\LaravelVoice\Events\HandoffRequested;
use EasyAI\LaravelVoice\Events\ResponseChunkReceived;
use EasyAI\LaravelVoice\Events\ResponseSynthesized;
use EasyAI\LaravelVoice\Events\SessionEnded;
use EasyAI\LaravelVoice\Events\SessionStarted;
use EasyAI\LaravelVoice\Events\SpeechTranscribed;
use EasyAI\LaravelVoice\Events\ToolCallCompleted;
use EasyAI\LaravelVoice\Events\ToolCallStarted;
use EasyAI\LaravelVoice\Events\VoiceError;
use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Exceptions\VoiceException;
use EasyAI\LaravelVoice\Exceptions\VoiceLimitExceededException;
use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Models\VoiceTurn;
use EasyAI\LaravelVoice\Support\CurrentVoiceSession;
use EasyAI\LaravelVoice\VoiceManager;

class VoiceAgent
{
    protected ?string $sttDriver = null;

    protected ?string $ttsDriver = null;

    protected ?string $llmProvider = null;

    protected array $tools = [];

    protected ?string $systemPrompt = null;

    protected int $maxSteps = 5;

    protected int $maxTurns = 50;

    protected int $maxSessionSeconds = 1800;

    protected int $contextTurns = 10;

    protected bool $streaming = false;

    public function __construct(protected string $name, protected VoiceManager $voice)
    {
    }

    public function stt(string $driver): static
    {
        $this->sttDriver = $driver;

        return $this;
    }

    public function tts(string $driver): static
    {
        $this->ttsDriver = $driver;

        return $this;
    }

    public function llm(string $provider): static
    {
        $this->llmProvider = $provider;

        return $this;
    }

    /** @param  \EasyAI\LaravelAI\Agent\Tool[]  $tools */
    public function tools(array $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    public function systemPrompt(string $prompt): static
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    public function maxSteps(int $steps): static
    {
        $this->maxSteps = $steps;

        return $this;
    }

    /**
     * Abuse/cost guards: a voice turn costs real money (STT + LLM + TTS),
     * so unlike a text chat these are enforced by default, not opt-in.
     */
    public function limits(int $maxTurns, int $maxSessionSeconds): static
    {
        $this->maxTurns = $maxTurns;
        $this->maxSessionSeconds = $maxSessionSeconds;

        return $this;
    }

    public function contextTurns(int $turns): static
    {
        $this->contextTurns = $turns;

        return $this;
    }

    /**
     * When enabled, handleTurn() fires ResponseChunkReceived as the LLM's
     * reply streams in, so a caller (a broadcast listener, an SSE
     * endpoint) can show the text appearing progressively. This only
     * affects the text response - TTS still only ever synthesizes the
     * complete final text once the turn finishes, since none of the
     * shipped TTS providers support streaming audio output.
     */
    public function streamResponses(bool $enabled = true): static
    {
        $this->streaming = $enabled;

        return $this;
    }

    public function startSession(array $attributes = []): VoiceSession
    {
        $session = VoiceSession::create(array_merge([
            'agent' => $this->name,
            'provider_stt' => $this->sttDriver,
            'provider_tts' => $this->ttsDriver,
            'provider_llm' => $this->llmProvider,
            'status' => 'active',
            'started_at' => now(),
        ], $attributes));

        event(new SessionStarted($session));

        return $session;
    }

    public function endSession(VoiceSession $session): void
    {
        if ($session->status === 'ended') {
            return;
        }

        $session->update(['status' => 'ended', 'ended_at' => now()]);

        event(new SessionEnded($session));
    }

    /**
     * Signals that a human should take over - typically called from a
     * tool's handler once the agent recognizes it cannot resolve the
     * request. Does not end the session by itself (the human may keep
     * talking to the same caller through a different channel this
     * package doesn't manage yet) - call completeHandoff() once the
     * handoff has actually happened.
     */
    public function requestHandoff(VoiceSession $session, string $reason = ''): void
    {
        event(new HandoffRequested($session, $reason));
    }

    /**
     * Ends the session with the handoff reason recorded in its metadata,
     * rather than a dedicated status value - adding a new
     * voice_sessions.status enum member would need a raw, per-database
     * ALTER statement this package cannot safely verify against every
     * supported database engine. 'ended' plus metadata carries the same
     * information a host app needs to tell a handed-off session apart
     * from a normal one, without that risk.
     */
    public function completeHandoff(VoiceSession $session, string $reason = ''): void
    {
        $session->update([
            'status' => 'ended',
            'ended_at' => now(),
            'metadata' => array_merge($session->metadata ?? [], [
                'handoff' => true,
                'handoff_reason' => $reason,
            ]),
        ]);

        event(new HandoffCompleted($session, $reason));
        event(new SessionEnded($session));
    }

    /**
     * Runs one full voice turn: STT -> EasyAI agent loop (with tools) ->
     * TTS. Persists a user VoiceTurn and an assistant VoiceTurn, firing
     * events at each stage. A failure at any stage is recorded on the
     * relevant turn and re-thrown - the caller (an HTTP controller, a
     * queued job, whatever the host app builds) decides how to surface it,
     * this method never swallows an error silently.
     */
    public function handleTurn(VoiceSession $session, string $audioFilePath, array $options = []): VoiceTurn
    {
        $this->guardSessionIsUsable($session);

        $sequence = ((int) $session->turns()->max('sequence')) + 1;

        $userTurn = VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => $sequence,
            'speaker' => 'user',
            'status' => 'pending',
        ]);

        try {
            $transcription = $this->voice->stt($this->sttDriver)->transcribe($audioFilePath, $options['stt'] ?? []);
        } catch (\Throwable $e) {
            $userTurn->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            event(new VoiceError($session, 'stt', $e));

            throw $e;
        }

        $sttDurationMs = $transcription->durationSeconds !== null
            ? (int) round($transcription->durationSeconds * 1000)
            : null;

        $userTurn->update([
            'transcript' => $transcription->text,
            'audio_duration_ms' => $sttDurationMs,
            'status' => 'completed',
        ]);

        if ($sttDurationMs !== null) {
            $session->increment('total_stt_ms', $sttDurationMs);
        }

        event(new SpeechTranscribed($session, $userTurn));

        $assistantTurn = VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => $sequence + 1,
            'speaker' => 'assistant',
            'status' => 'pending',
        ]);

        try {
            $started = microtime(true);

            $messages = $this->buildMessageHistory($session, $sequence);
            $messages[] = ['role' => 'user', 'content' => $transcription->text];

            $provider = AI::provider($this->llmProvider);

            if ($this->systemPrompt !== null) {
                $provider->systemPrompt($this->systemPrompt);
            }

            if ($this->tools !== []) {
                $provider->tools($this->tools);
            }

            // run() returns only the *final* step's response - once the
            // loop converges to a text answer that response's own
            // getToolCalls() is empty by definition. Tool calls made
            // earlier in the loop are only ever visible through this
            // callback, so they're accumulated here instead.
            $toolCallLog = [];

            $onChunk = $this->streaming
                ? function (string $chunk, string $type = 'content') use ($session, $assistantTurn) {
                    event(new ResponseChunkReceived($session, $assistantTurn, $chunk, $type));
                }
                : null;

            // Exposes the active session to a tool's handler for the exact
            // duration of this call - Tool::execute() only ever receives
            // the LLM's parsed arguments, with no way to pass session
            // context through it otherwise. See CurrentVoiceSession's own
            // docblock for why this exists (the memory tools need it).
            CurrentVoiceSession::set($session);

            try {
                $response = $provider->run($messages, $this->maxSteps, function ($call, $result) use ($session, &$toolCallLog) {
                    // run()'s callback only ever hands back the ToolCall/result,
                    // never the matched Tool instance - looked up here by name
                    // against this agent's own $this->tools so an
                    // AuthorizedTool-made tool's tier can be surfaced on both
                    // events.
                    $tier = null;
                    foreach ($this->tools as $tool) {
                        if ($tool->name === $call->name) {
                            $tier = AuthorizedTool::tierOf($tool);
                            break;
                        }
                    }

                    // AbstractDriver::run() only exposes a single post-execution
                    // hook - there is no separate pre-execution callback to fire
                    // ToolCallStarted from with accurate timing, so both events
                    // fire back-to-back here rather than pretending otherwise.
                    event(new ToolCallStarted($session, $call->name, $call->arguments, $tier));
                    event(new ToolCallCompleted($session, $call->name, $result, $tier));

                    $toolCallLog[] = ['name' => $call->name, 'arguments' => $call->arguments, 'tier' => $tier];
                }, $onChunk);
            } finally {
                CurrentVoiceSession::clear();
            }

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            $assistantTurn->update([
                'transcript' => $response->getContent(),
                'tool_calls' => $toolCallLog ?: null,
                'latency_ms' => $latencyMs,
                'status' => 'completed',
            ]);

            $session->increment('total_prompt_tokens', $response->getPromptTokens());
            $session->increment('total_completion_tokens', $response->getCompletionTokens());
            $this->accumulateCost($session, $response->getEstimatedCost());

            $this->mirrorIntoChatHistory($session, $userTurn, $assistantTurn);
        } catch (\Throwable $e) {
            $assistantTurn->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            event(new VoiceError($session, 'llm', $e));

            // AI::provider()->run() throws LaravelEasyAI's OWN exception
            // types (EasyAI\LaravelAI\Exceptions\*), not this package's -
            // a real gap found live (an unreachable Ollama backend during
            // the LLM step produced a raw, unwrapped stack trace at the
            // HTTP layer instead of the generic 502 VoiceTurnController's
            // catch block gives STT/TTS failures, since that catch only
            // matches EasyAI\LaravelVoice\Exceptions\*). Re-wrapped here so
            // every caller of handleTurn() - this HTTP controller or any
            // other consumer - gets one consistent exception contract
            // regardless of which downstream step failed.
            throw match (true) {
                $e instanceof \EasyAI\LaravelAI\Exceptions\ConnectionException => new ConnectionException($e->getMessage(), $e->getProvider(), $e->getContext(), $e->getCode(), $e),
                $e instanceof \EasyAI\LaravelAI\Exceptions\ProviderException => new ProviderException($e->getMessage(), $e->getProvider(), $e->getContext(), $e->getCode(), $e),
                default => $e,
            };
        }

        try {
            $audio = $this->voice->tts($this->ttsDriver)->synthesize($assistantTurn->transcript, $options['tts'] ?? []);
            $path = $this->storeAudio($session, $assistantTurn, $audio);

            $ttsDurationMs = $audio->durationSeconds !== null
                ? (int) round($audio->durationSeconds * 1000)
                : null;

            $assistantTurn->update([
                'audio_path' => $path,
                'audio_duration_ms' => $ttsDurationMs,
            ]);

            if ($ttsDurationMs !== null) {
                $session->increment('total_tts_ms', $ttsDurationMs);
            }

            event(new ResponseSynthesized($session, $assistantTurn));
        } catch (\Throwable $e) {
            // The LLM's text answer is already saved - a TTS failure never
            // discards it. The caller can still show/return the text.
            event(new VoiceError($session, 'tts', $e));

            throw $e;
        }

        return $assistantTurn->refresh();
    }

    /**
     * Adds this exchange to EasyAI's own chat history when the session is
     * explicitly linked to one (voice_sessions.chat_session_id) - opt-in,
     * off by default. Lets a voice call and a text chat share one
     * transcript/memory window in EasyAI's UI, without this package
     * maintaining a second conversation store. Runs before TTS so a
     * synthesis failure never prevents the exchange from being recorded.
     */
    protected function mirrorIntoChatHistory(VoiceSession $session, VoiceTurn $userTurn, VoiceTurn $assistantTurn): void
    {
        if ($session->chat_session_id === null) {
            return;
        }

        ChatMessage::create([
            'chat_session_id' => $session->chat_session_id,
            'role' => 'user',
            'content' => (string) $userTurn->transcript,
        ]);

        ChatMessage::create([
            'chat_session_id' => $session->chat_session_id,
            'role' => 'assistant',
            'content' => (string) $assistantTurn->transcript,
        ]);
    }

    /**
     * getEstimatedCost() returns null unless ai.pricing.{provider}.{model}
     * is configured (EasyAI never guesses a price) - accumulated manually
     * rather than via increment() because SQL's `NULL + amount` evaluates
     * to NULL, which would leave voice_sessions.estimated_cost stuck at
     * NULL forever the first time a null was ever added to it.
     */
    protected function accumulateCost(VoiceSession $session, ?float $cost): void
    {
        if ($cost === null) {
            return;
        }

        $session->update(['estimated_cost' => ($session->estimated_cost ?? 0) + $cost]);
    }

    protected function guardSessionIsUsable(VoiceSession $session): void
    {
        if ($session->status !== 'active') {
            throw new VoiceException("Voice session #{$session->id} is not active.");
        }

        // Each exchange writes two rows (a user turn and an assistant
        // turn) - the limit is expressed in exchanges, so only user turns
        // are counted.
        if ($session->turns()->where('speaker', 'user')->count() >= $this->maxTurns) {
            $this->endSession($session);

            throw new VoiceLimitExceededException(
                "Voice session #{$session->id} reached its maximum of {$this->maxTurns} turns."
            );
        }

        if ($session->started_at !== null && $session->started_at->diffInSeconds(now()) > $this->maxSessionSeconds) {
            $this->endSession($session);

            throw new VoiceLimitExceededException(
                "Voice session #{$session->id} exceeded its maximum duration of {$this->maxSessionSeconds} seconds."
            );
        }
    }

    protected function buildMessageHistory(VoiceSession $session, int $beforeSequence): array
    {
        return $session->turns()
            ->where('sequence', '<', $beforeSequence)
            ->where('status', 'completed')
            ->orderByDesc('sequence')
            ->limit($this->contextTurns * 2)
            ->get()
            ->sortBy('sequence')
            ->values()
            ->map(fn (VoiceTurn $turn) => [
                'role' => $turn->speaker,
                'content' => (string) $turn->transcript,
            ])
            ->all();
    }

    protected function storeAudio(VoiceSession $session, VoiceTurn $turn, \EasyAI\LaravelVoice\Support\AudioResult $audio): string
    {
        $disk = config('voice.storage.disk', 'local');
        $extension = str_contains($audio->mimeType, 'mpeg') ? 'mp3' : 'audio';
        $path = "voice/{$session->id}/{$turn->id}.{$extension}";

        \Illuminate\Support\Facades\Storage::disk($disk)->put($path, $audio->binary);

        return $path;
    }
}
