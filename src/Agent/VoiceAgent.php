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
use EasyAI\LaravelVoice\Events\TurnRecovered;
use EasyAI\LaravelVoice\Events\VoiceError;
use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Exceptions\TurnOwnershipLostException;
use EasyAI\LaravelVoice\Exceptions\VoiceException;
use EasyAI\LaravelVoice\Exceptions\VoiceLimitExceededException;
use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Models\VoiceTurn;
use EasyAI\LaravelVoice\Support\CurrentVoiceSession;
use EasyAI\LaravelVoice\VoiceManager;
use Illuminate\Support\Facades\DB;

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
     *
     * $options['idempotency_key'] (optional): identifies one logical turn
     * attempt. A second call with the same key for the same session never
     * re-runs STT/LLM/tools/TTS or charges usage twice - see
     * claimNextTurn()/resolveExistingClaim() for the exact contract,
     * including how a stale (owning-process-gone) pending turn is safely
     * recovered rather than left stuck forever.
     *
     * At-least-once, not exactly-once: if a stale attempt is recovered
     * after its LLM step already called a tool, that tool may be called
     * again - this package has no way to know whether an arbitrary tool
     * handler's own external side effect already happened before the
     * original process died. Tool-level idempotency for anything with a
     * real side effect is the consuming application's own responsibility.
     */
    public function handleTurn(VoiceSession $session, string $audioFilePath, array $options = []): VoiceTurn
    {
        // An explicitly empty key (e.g. a header sent with no value) is
        // treated the same as no key at all - normalized here, the single
        // place both the HTTP controller and any direct caller flow
        // through, rather than every caller having to remember to do it.
        // An empty string is otherwise a normal, non-NULL value under the
        // unique index and would incorrectly self-collide.
        $idempotencyKey = $options['idempotency_key'] ?? null;
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        $claim = $this->claimNextTurn($session, $idempotencyKey);

        if ($claim['outcome'] === 'limit_exceeded') {
            // claimNextTurn() already committed the 'ended' status on its
            // own locked copy of this session - refreshed here so both the
            // event payload and this object's own state match what's now
            // durably persisted, exactly as the old direct endSession($session)
            // call (update() keeps its model's in-memory attributes in sync)
            // already did before this method existed.
            $session->refresh();

            event(new SessionEnded($session));

            throw new VoiceLimitExceededException($claim['message']);
        }

        if ($claim['outcome'] === 'duplicate_completed' || $claim['outcome'] === 'duplicate_failed') {
            return $this->resolveDuplicateTurn($claim);
        }

        if ($claim['outcome'] === 'duplicate_pending') {
            throw new VoiceException('A turn for this idempotency key is still processing.');
        }

        if ($claim['outcome'] === 'recover_assistant_turn') {
            // The user turn already completed on the abandoned attempt -
            // its transcript is real, paid-for work; STT is never repeated
            // for it. Only the LLM/tool/TTS portion resumes, reusing the
            // SAME (just-reclaimed) assistant turn row rather than
            // creating a new one - no new sequence, no idempotency-key
            // collision.
            $userTurn = $claim['userTurn'];
            $assistantTurn = $claim['assistantTurn'];

            event(new TurnRecovered($session, $assistantTurn, 'llm'));

            return $this->runLlmAndTts($session, $userTurn, $assistantTurn, (string) $userTurn->transcript, $options);
        }

        // 'proceed' (a genuinely new turn) and 'recover_user_turn' (a
        // stale, never-completed user turn reclaimed onto the SAME row)
        // both run STT from here - the only difference is whether
        // $userTurn is a freshly created row or a reclaimed existing one;
        // the rest of the pipeline is identical either way.
        $sequence = $claim['sequence'];
        $userTurn = $claim['userTurn'];

        if ($claim['outcome'] === 'recover_user_turn') {
            event(new TurnRecovered($session, $userTurn, 'stt'));
        }

        try {
            $transcription = $this->voice->stt($this->sttDriver)->transcribe($audioFilePath, $options['stt'] ?? []);
        } catch (\Throwable $e) {
            if (! $this->guardedTurnUpdate($userTurn, ['status' => 'failed', 'error_message' => $e->getMessage()])) {
                throw $this->ownershipLostException($userTurn);
            }

            event(new VoiceError($session, 'stt', $e));

            throw $e;
        }

        $sttDurationMs = $transcription->durationSeconds !== null
            ? (int) round($transcription->durationSeconds * 1000)
            : null;

        // Guarded, not a plain update(): a slow-but-still-alive original
        // execution reaching this line AFTER another execution has
        // already reclaimed $userTurn (see reclaim()) must not be able to
        // silently overwrite the recovered attempt's own result, nor
        // charge STT usage for work whose outcome was already discarded.
        // 0 affected rows means exactly that has happened - this
        // execution no longer owns the turn, and must stop here rather
        // than proceed to create an assistant turn or touch the session.
        if (! $this->guardedTurnUpdate($userTurn, [
            'transcript' => $transcription->text,
            'audio_duration_ms' => $sttDurationMs,
            'status' => 'completed',
        ])) {
            throw $this->ownershipLostException($userTurn);
        }

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

        return $this->runLlmAndTts($session, $userTurn, $assistantTurn, $transcription->text, $options);
    }

    /**
     * The LLM/tool-calling loop plus TTS - extracted from handleTurn()
     * unchanged in behavior, only parameterized by the already-persisted
     * transcript text rather than reading it off a freshly-completed STT
     * call, so a recovered assistant turn (STT already done on a prior,
     * abandoned attempt) can resume here directly without repeating STT.
     */
    protected function runLlmAndTts(VoiceSession $session, VoiceTurn $userTurn, VoiceTurn $assistantTurn, string $transcriptText, array $options): VoiceTurn
    {
        try {
            $started = microtime(true);

            $messages = $this->buildMessageHistory($session, $userTurn->sequence);
            $messages[] = ['role' => 'user', 'content' => $transcriptText];

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

            // A tool-calling turn makes more than one real LLM call, each
            // with its own usage - run() only ever returns the LAST step's
            // response, so reading getPromptTokens()/getCompletionTokens()/
            // getEstimatedCost() off it alone would silently drop every
            // earlier step's usage. $onStep fires once per step with that
            // step's real AIResponse - each step's usage/cost is persisted
            // atomically HERE, immediately, rather than accumulated in a
            // local variable and written once at the end: if the process
            // dies partway through a multi-step tool-calling loop, whatever
            // steps already ran are not silently lost from the session's
            // running totals. For an ordinary single-step turn this still
            // fires exactly once, with the same net effect as before.
            //
            // Ownership-checked first: a multi-step loop can run long
            // enough for another execution to have reclaimed
            // $assistantTurn since this one started (see reclaim()) -
            // stillOwnsTurn() catches that before crediting a step whose
            // result this execution's own turn row no longer represents.
            // Throwing here stops the loop immediately (run() calls
            // $onStep with nothing wrapping it - see AbstractDriver::run()),
            // so no further step runs and TTS is never reached either.
            $onStep = function ($stepResponse) use ($session, $assistantTurn) {
                if (! $this->stillOwnsTurn($assistantTurn)) {
                    throw $this->ownershipLostException($assistantTurn);
                }

                $session->increment('total_prompt_tokens', $stepResponse->getPromptTokens());
                $session->increment('total_completion_tokens', $stepResponse->getCompletionTokens());

                // getEstimatedCost() is null unless a rate is configured for
                // that step's exact provider/model - never invented here.
                $this->accumulateCost($session, $stepResponse->getEstimatedCost());
            };

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
                }, $onChunk, $onStep);
            } finally {
                CurrentVoiceSession::clear();
            }

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if (! $this->guardedTurnUpdate($assistantTurn, [
                'transcript' => $response->getContent(),
                'tool_calls' => $toolCallLog ?: null,
                'latency_ms' => $latencyMs,
                'status' => 'completed',
            ])) {
                throw $this->ownershipLostException($assistantTurn);
            }

            $this->mirrorIntoChatHistory($session, $userTurn, $assistantTurn);
        } catch (TurnOwnershipLostException $e) {
            // Already lost ownership before or during this try block - the
            // write that would normally record a failure below is skipped
            // entirely: it isn't this execution's row anymore, and firing
            // VoiceError('llm', ...) here would misleadingly describe a
            // genuine LLM failure for a turn another execution may since
            // have completed successfully.
            throw $e;
        } catch (\Throwable $e) {
            if (! $this->guardedTurnUpdate($assistantTurn, ['status' => 'failed', 'error_message' => $e->getMessage()])) {
                throw $this->ownershipLostException($assistantTurn);
            }

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

            if (! $this->guardedTurnUpdate($assistantTurn, [
                'audio_path' => $path,
                'audio_duration_ms' => $ttsDurationMs,
            ])) {
                throw $this->ownershipLostException($assistantTurn);
            }

            if ($ttsDurationMs !== null) {
                $session->increment('total_tts_ms', $ttsDurationMs);
            }

            event(new ResponseSynthesized($session, $assistantTurn));
        } catch (\Throwable $e) {
            // The LLM's text answer is already saved - a TTS failure never
            // discards it. The caller can still show/return the text. Not
            // fired for an ownership-loss exception - that isn't a genuine
            // TTS failure and attributing one to this turn would mislead.
            if (! $e instanceof TurnOwnershipLostException) {
                event(new VoiceError($session, 'tts', $e));
            }

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
     * is configured (EasyAI never guesses a price) - never invented here.
     *
     * Deliberately not a plain increment(): SQL's `NULL + amount`
     * evaluates to NULL, which would leave voice_sessions.estimated_cost
     * stuck at NULL forever the first time a null was ever added to it.
     * Also deliberately not `$session->update(['estimated_cost' =>
     * ($session->estimated_cost ?? 0) + $cost])` - that reads the PHP
     * object's possibly-stale in-memory value and writes a PHP-computed
     * literal back, a classic lost-update race if two turns for the same
     * session accumulate cost concurrently (the second overwrites the
     * first's contribution with a total computed from a read that never
     * saw it). COALESCE(...) + ? inside the UPDATE itself makes the whole
     * read-modify-write atomic at the database level in one statement,
     * with $cost passed as a bound parameter rather than interpolated.
     */
    protected function accumulateCost(VoiceSession $session, ?float $cost): void
    {
        if ($cost === null) {
            return;
        }

        $session->getConnection()->statement(
            'update '.$session->getTable().' set estimated_cost = coalesce(estimated_cost, 0) + ? where '.$session->getKeyName().' = ?',
            [$cost, $session->getKey()]
        );
    }

    /**
     * The entire locked, DB-only claim step for one handleTurn() call:
     * lock the session row, resolve an idempotency-key duplicate (or a
     * stale-turn recovery - see resolveExistingClaim()) if a key was
     * given, enforce maxTurns/maxSessionSeconds, and - only once none of
     * that short-circuits - claim the next sequence and insert the
     * pending user turn. Everything here is a fast local read/write; STT/
     * LLM/tool-calling/TTS all happen after this method returns and the
     * transaction has released the lock, never inside it.
     *
     * Returns an outcome descriptor rather than throwing directly for the
     * two cases with a durable side effect (limit_exceeded ends the
     * session) - throwing from inside DB::transaction() rolls the whole
     * transaction back, which would silently undo that status change.
     * The caller inspects the outcome and throws/returns only after the
     * transaction has committed.
     *
     * @return array{outcome: 'proceed', sequence: int, userTurn: VoiceTurn}
     *       | array{outcome: 'limit_exceeded', message: string}
     *       | (see resolveExistingClaim() for every outcome an existing
     *          idempotency-key match can produce)
     */
    protected function claimNextTurn(VoiceSession $session, ?string $idempotencyKey): array
    {
        return DB::transaction(function () use ($session, $idempotencyKey) {
            $locked = VoiceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            // Resolved before the active/limit checks below - a client
            // asking "what happened to my earlier request?" gets an
            // answer regardless of whether the session has since ended,
            // rather than being told the session isn't usable anymore.
            // Still fully inside this same lock, so the whole decision -
            // including a stale-turn reclaim - is atomic with respect to
            // any other handleTurn() call for this session.
            if ($idempotencyKey !== null) {
                $existingUserTurn = VoiceTurn::query()
                    ->where('voice_session_id', $locked->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingUserTurn !== null) {
                    return $this->resolveExistingClaim($session, $existingUserTurn);
                }
            }

            if ($locked->status !== 'active') {
                throw new VoiceException("Voice session #{$session->id} is not active.");
            }

            // Each exchange writes two rows (a user turn and an assistant
            // turn) - the limit is expressed in exchanges, so only user
            // turns are counted.
            if ($locked->turns()->where('speaker', 'user')->count() >= $this->maxTurns) {
                $locked->update(['status' => 'ended', 'ended_at' => now()]);

                return [
                    'outcome' => 'limit_exceeded',
                    'message' => "Voice session #{$session->id} reached its maximum of {$this->maxTurns} turns.",
                ];
            }

            if ($locked->started_at !== null && $locked->started_at->diffInSeconds(now()) > $this->maxSessionSeconds) {
                $locked->update(['status' => 'ended', 'ended_at' => now()]);

                return [
                    'outcome' => 'limit_exceeded',
                    'message' => "Voice session #{$session->id} exceeded its maximum duration of {$this->maxSessionSeconds} seconds.",
                ];
            }

            $sequence = ((int) $locked->turns()->max('sequence')) + 1;

            $userTurn = VoiceTurn::create([
                'voice_session_id' => $session->id,
                'sequence' => $sequence,
                'speaker' => 'user',
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
            ]);

            return ['outcome' => 'proceed', 'sequence' => $sequence, 'userTurn' => $userTurn];
        });
    }

    /**
     * Called from inside claimNextTurn()'s transaction/lock, for a key
     * that already matches an existing user turn. Determines the real
     * outcome - never from the user turn alone: an LLM failure leaves the
     * user turn 'completed' (STT succeeded) with the *assistant* turn
     * 'failed', and a TTS failure leaves the assistant turn 'completed'
     * with a null audio_path (existing, unchanged behavior) - so the
     * paired assistant turn (sequence = user turn's sequence + 1,
     * guaranteed unique by the Phase 9A index) is always consulted too.
     *
     * A genuinely stale 'pending' row (user or assistant) is reclaimed
     * right here, atomically, via reclaim() - never a read-then-act
     * check. A turn that's 'pending' but not yet stale returns
     * 'duplicate_pending' exactly as before Phase 9C. A stale completed
     * user turn whose assistant turn was never created (see the
     * $assistantTurn === null branch below) is handled the same way in
     * spirit, staleness checked against the user turn's own updated_at
     * since there is no 'pending' row for reclaim() itself to act on.
     *
     * @return array{outcome: 'duplicate_completed'|'duplicate_failed', turn: VoiceTurn}
     *       | array{outcome: 'duplicate_pending'}
     *       | array{outcome: 'recover_user_turn', sequence: int, userTurn: VoiceTurn}
     *       | array{outcome: 'recover_assistant_turn', userTurn: VoiceTurn, assistantTurn: VoiceTurn}
     */
    protected function resolveExistingClaim(VoiceSession $session, VoiceTurn $userTurn): array
    {
        $staleCutoff = now()->subSeconds((int) config('voice.turn_recovery.stale_after_seconds', 300));

        if ($userTurn->status === 'pending') {
            if (! $this->reclaim($userTurn, $staleCutoff)) {
                return ['outcome' => 'duplicate_pending'];
            }

            // Reclaimed onto the SAME row - no new turn, no new sequence,
            // the idempotency key is untouched (it still belongs to this
            // same logical attempt).
            return ['outcome' => 'recover_user_turn', 'sequence' => $userTurn->sequence, 'userTurn' => $userTurn->fresh()];
        }

        if ($userTurn->status === 'failed') {
            return ['outcome' => 'duplicate_failed', 'turn' => $userTurn];
        }

        // $userTurn->status === 'completed' from here.
        $assistantTurn = VoiceTurn::query()
            ->where('voice_session_id', $session->id)
            ->where('sequence', $userTurn->sequence + 1)
            ->where('speaker', 'assistant')
            ->first();

        if ($assistantTurn === null) {
            // STT completed but the assistant turn was never even created
            // - the owning process died in the gap between the two
            // writes. Nothing 'pending' exists here for reclaim() to act
            // on, so staleness is checked directly against the user
            // turn's own updated_at instead: fresh (STT genuinely just
            // finished a moment ago, the assistant turn simply hasn't
            // been created yet) is still 'still processing', unchanged
            // from before Phase 9C's stale-completed-user-turn handling.
            if (! $userTurn->updated_at->lt($staleCutoff)) {
                return ['outcome' => 'duplicate_pending'];
            }

            // Stale - safe to create the missing assistant turn here:
            // this whole method runs inside claimNextTurn()'s existing
            // session-row lock, so a second, simultaneous same-key retry
            // blocks until this transaction commits, then sees the row
            // this call is about to create rather than racing to create
            // a second one for the same sequence. A defensive catch
            // remains for the narrow, pre-existing case where an
            // unrelated turn (a different key, created and committed
            // while this one sat stuck) already claimed this exact
            // sequence number - conflict is reported rather than a raw
            // database error.
            //
            // Narrowed to SQLSTATE 23000 (the integrity-constraint-
            // violation class MySQL, MariaDB, SQLite, and PostgreSQL all
            // report a unique/foreign-key conflict under) specifically -
            // not every QueryException. A lost connection, a deadlock, a
            // disk-full error, or any other genuine database failure at
            // this exact call site must still surface as a real error,
            // never be silently reinterpreted as "someone else already
            // has this," which would hide an actual outage behind a
            // misleadingly benign 409.
            try {
                $assistantTurn = VoiceTurn::create([
                    'voice_session_id' => $session->id,
                    'sequence' => $userTurn->sequence + 1,
                    'speaker' => 'assistant',
                    'status' => 'pending',
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                return ['outcome' => 'duplicate_pending'];
            }

            return ['outcome' => 'recover_assistant_turn', 'userTurn' => $userTurn, 'assistantTurn' => $assistantTurn];
        }

        if ($assistantTurn->status === 'failed') {
            return ['outcome' => 'duplicate_failed', 'turn' => $assistantTurn];
        }

        if ($assistantTurn->status === 'completed') {
            return ['outcome' => 'duplicate_completed', 'turn' => $assistantTurn];
        }

        // $assistantTurn->status === 'pending' from here.
        if (! $this->reclaim($assistantTurn, $staleCutoff)) {
            return ['outcome' => 'duplicate_pending'];
        }

        return ['outcome' => 'recover_assistant_turn', 'userTurn' => $userTurn, 'assistantTurn' => $assistantTurn->fresh()];
    }

    /**
     * The one atomic, DB-level reclaim operation Phase 9C relies on: a
     * single UPDATE with `status = 'pending' AND updated_at < $staleCutoff`
     * in its WHERE clause. Returns whether THIS call won the reclaim - 0
     * affected rows means the row wasn't actually stale (a legitimately
     * in-flight attempt, still within the configured threshold), or
     * another request already reclaimed/changed it a moment ago. This is
     * never a read-then-act check: the condition and the write happen in
     * one database round trip, so there is no window for a second caller
     * to observe "not yet reclaimed" and also win. (On SQLite, which has
     * no real row-level lock, this single atomic UPDATE is the only thing
     * that actually prevents two callers from both believing they won;
     * under MySQL/MariaDB it's additionally covered by the session-row
     * lock claimNextTurn() already holds - see that method's own
     * docblock. Real concurrent-process behavior under InnoDB is not and
     * cannot be proven by this SQLite-based test suite.)
     *
     * The abandoned attempt is marked 'interrupted' - reusing the
     * existing, already-defined-but-previously-unused status value rather
     * than adding a new one - then immediately reset to 'pending' with a
     * fresh updated_at for the new attempt that's about to run on this
     * same row. By the time reclaim() returns true, no further race is
     * possible (the affected-rows check already proved exclusive
     * ownership), so that second write needs no condition of its own.
     */
    protected function reclaim(VoiceTurn $turn, \DateTimeInterface $staleCutoff): bool
    {
        $affected = VoiceTurn::query()
            ->whereKey($turn->id)
            ->where('status', 'pending')
            ->where('updated_at', '<', $staleCutoff)
            ->update(['status' => 'interrupted']);

        if ($affected === 0) {
            return false;
        }

        $turn->update(['status' => 'pending']);

        return true;
    }

    /**
     * The execution-ownership fence for everything that happens to a
     * turn after claimNextTurn() returns - reclaim()'s own atomic UPDATE
     * only protects the reclaim decision itself; nothing about it stops
     * an execution that already had its own (now stale) copy of $turn
     * loaded from simply continuing to run and writing to the same row
     * once another execution has reclaimed it, since a DB write made by a
     * different process never touches this process's already-loaded
     * Eloquent object. This closes that gap: the write only takes effect
     * if $turn's row still has the exact updated_at this execution last
     * observed - reclaim() (or another execution's own guarded write)
     * always changes it, so a mismatch here means ownership has moved on.
     *
     * 0 affected rows leaves $turn's own attributes untouched and returns
     * false; the caller must treat that as "stop processing this turn"
     * (see ownershipLostException()), never as if the write had happened.
     * On success, $turn is refreshed from the database (not just patched
     * with $attributes in memory) so a chain of several guarded writes
     * against the same turn within one execution - the LLM-completion
     * write followed by the TTS-completion write, for instance - each
     * correctly fence against what this execution itself most recently
     * wrote, regardless of the column's actual stored timestamp precision.
     */
    protected function guardedTurnUpdate(VoiceTurn $turn, array $attributes): bool
    {
        $affected = VoiceTurn::query()
            ->whereKey($turn->id)
            ->where('updated_at', $turn->updated_at)
            ->update($attributes);

        if ($affected === 0) {
            return false;
        }

        $turn->refresh();

        return true;
    }

    /**
     * A read-only ownership check for a call site that doesn't itself
     * write to $turn - the $onStep usage callback, which credits the
     * SESSION's counters, not the turn row, so guardedTurnUpdate()'s
     * conditional UPDATE doesn't apply directly. Same fencing token
     * (updated_at) and the same "has anything changed this row since I
     * last observed it" question, just via a read instead of a write.
     *
     * This has an inherent, narrow TOCTOU gap between the check and
     * whatever the caller does next (crediting usage is not part of one
     * atomic database statement with this check) - accepted here for the
     * same reason recovery as a whole is already documented as
     * at-least-once rather than exactly-once: closing it completely would
     * need a single atomic "increment session usage AND verify turn
     * ownership" statement spanning two different tables, which is out of
     * proportion to a race whose window is microseconds against a
     * staleness threshold measured in minutes.
     */
    protected function stillOwnsTurn(VoiceTurn $turn): bool
    {
        return VoiceTurn::query()
            ->whereKey($turn->id)
            ->where('updated_at', $turn->updated_at)
            ->exists();
    }

    /**
     * The one message used everywhere a guarded write/check detects that
     * this execution no longer owns a turn - reused rather than
     * constructed ad hoc at each call site so every ownership-loss
     * exception reads identically regardless of which stage detected it.
     */
    protected function ownershipLostException(VoiceTurn $turn): TurnOwnershipLostException
    {
        return new TurnOwnershipLostException(
            "Voice turn #{$turn->id} was recovered by another attempt before this one finished."
        );
    }

    /**
     * Formats an already-determined duplicate outcome (completed or
     * failed) into what handleTurn() returns or throws. The actual
     * pair-inspection that decided this outcome already happened under
     * the session lock, inside claimNextTurn()/resolveExistingClaim() -
     * this only turns that decision into the right return value or
     * exception. Never touches STT, the LLM/tool loop, or TTS.
     */
    protected function resolveDuplicateTurn(array $claim): VoiceTurn
    {
        $turn = $claim['turn'];

        if ($claim['outcome'] === 'duplicate_failed') {
            throw new VoiceException($turn->error_message ?: 'This request previously failed and will not be retried under the same idempotency key.');
        }

        return $turn;
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
