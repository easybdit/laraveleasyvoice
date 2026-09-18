<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Agent\AuthorizedTool;
use EasyAI\LaravelVoice\Events\TurnRecovered;
use EasyAI\LaravelVoice\Exceptions\TurnOwnershipLostException;
use EasyAI\LaravelVoice\Exceptions\VoiceException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Models\VoiceTurn;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 9C: a genuinely stale `pending` turn (its owning process gone -
 * a crash, a timeout, a killed worker) is safely recovered when the same
 * Idempotency-Key is retried, instead of returning 409 forever. See
 * VoiceAgent::claimNextTurn()/resolveExistingClaim()/reclaim() for the
 * exact contract.
 */
class VoiceAgentRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Voice::registerAgent('receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')
                ->systemPrompt('You are a helpful receptionist.')
                ->limits(maxTurns: 50, maxSessionSeconds: 3600);
        });

        // A short, test-only threshold - production defaults are far more
        // conservative (config/voice.php's own docblock explains why).
        config(['voice.turn_recovery.stale_after_seconds' => 60]);
    }

    protected function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.mp3';
        file_put_contents($path, 'fake-audio-bytes');

        return $path;
    }

    protected function fakeChatCompletion(string $content): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'How many students are here today?']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => $content]]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);
    }

    protected function makeStaleTurn(VoiceSession $session, array $attributes): VoiceTurn
    {
        $turn = VoiceTurn::create(array_merge([
            'voice_session_id' => $session->id,
        ], $attributes));

        // Backdating updated_at directly via the query builder, bypassing
        // Eloquent's own auto-touch - the same "write directly to the
        // database, out from under any in-process state" technique
        // Phase 9A/9B's own tests already use, here simulating "this row
        // was last touched long before the configured stale threshold."
        DB::table('voice_turns')->where('id', $turn->id)->update([
            'updated_at' => now()->subSeconds(120),
        ]);

        return $turn->fresh();
    }

    public function test_a_non_stale_pending_duplicate_still_returns_409_and_is_not_reclaimed(): void
    {
        Http::fake(); // any outbound call at all fails the assertion below

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        // Recent updated_at - well within the 60s threshold configured in
        // setUp() - a genuinely in-flight attempt, not an abandoned one.
        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'pending',
            'idempotency_key' => 'attempt-1',
        ]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $this->expectException(VoiceException::class);
            $this->expectExceptionMessage('still processing');

            $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        Http::assertNothingSent();
    }

    public function test_a_stale_user_turn_is_recovered_reusing_the_same_row_and_sequence(): void
    {
        Event::fake();
        $this->fakeChatCompletion('42 students are present today.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $staleTurn = $this->makeStaleTurn($session, [
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'pending',
            'idempotency_key' => 'attempt-1',
        ]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('42 students are present today.', $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);

        // The SAME row was reused - no second user turn, no gap in
        // sequence, the original idempotency key is still on this one row.
        $userTurns = VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'user')->get();
        $this->assertCount(1, $userTurns);
        $this->assertSame($staleTurn->id, $userTurns->first()->id);
        $this->assertSame(1, $userTurns->first()->sequence);
        $this->assertSame('attempt-1', $userTurns->first()->idempotency_key);
        $this->assertSame('completed', $userTurns->first()->status);

        Event::assertDispatched(TurnRecovered::class, fn (TurnRecovered $e) => $e->stage === 'stt' && $e->turn->id === $staleTurn->id);
    }

    public function test_a_stale_assistant_turn_is_recovered_without_repeating_stt(): void
    {
        Event::fake();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => '42 students are present today.']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            // Deliberately no fake for audio/transcriptions - if STT were
            // called at all, Http::assertNotSent() below would catch it.
        ]);

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'completed',
            'transcript' => 'How many students are here today?',
            'idempotency_key' => 'attempt-1',
        ]);

        $staleAssistantTurn = $this->makeStaleTurn($session, [
            'sequence' => 2,
            'speaker' => 'assistant',
            'status' => 'pending',
        ]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $result = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('42 students are present today.', $result->transcript);
        $this->assertSame('completed', $result->status);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'audio/transcriptions'));

        // The SAME assistant turn row was reused - no new sequence, no
        // second assistant turn for this exchange.
        $assistantTurns = VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'assistant')->get();
        $this->assertCount(1, $assistantTurns);
        $this->assertSame($staleAssistantTurn->id, $assistantTurns->first()->id);
        $this->assertSame(2, $assistantTurns->first()->sequence);

        Event::assertDispatched(TurnRecovered::class, fn (TurnRecovered $e) => $e->stage === 'llm' && $e->turn->id === $staleAssistantTurn->id);
    }

    public function test_two_reclaim_attempts_for_the_same_row_only_one_can_win(): void
    {
        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $turn = $this->makeStaleTurn($session, [
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'pending',
            'idempotency_key' => 'attempt-1',
        ]);

        // The atomic reclaim operation itself, called twice in direct
        // sequence to deterministically prove its WHERE-conditioned UPDATE
        // only ever lets one caller through - not a claim that real
        // concurrent MySQL/MariaDB InnoDB processes were exercised (this
        // single-process SQLite suite cannot do that), only that the SQL
        // statement's own condition is correct: the second call's WHERE
        // clause (status = 'pending') no longer matches once the first
        // has already flipped it away from 'pending'.
        $reclaim = new \ReflectionMethod($agent, 'reclaim');
        $reclaim->setAccessible(true);
        $staleCutoff = now()->subSeconds(60);

        $firstWon = $reclaim->invoke($agent, $turn, $staleCutoff);
        $secondWon = $reclaim->invoke($agent, $turn->fresh(), $staleCutoff);

        $this->assertTrue($firstWon);
        $this->assertFalse($secondWon);
    }

    public function test_an_already_reclaimed_non_pending_turn_cannot_be_reclaimed_again(): void
    {
        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $turn = $this->makeStaleTurn($session, [
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'interrupted', // already reclaimed/settled by a prior attempt
            'idempotency_key' => 'attempt-1',
        ]);

        $reclaim = new \ReflectionMethod($agent, 'reclaim');
        $reclaim->setAccessible(true);

        $won = $reclaim->invoke($agent, $turn, now()->subSeconds(60));

        $this->assertFalse($won);
        $this->assertSame('interrupted', $turn->fresh()->status);
    }

    public function test_incremental_usage_is_persisted_after_each_llm_step_not_only_at_the_end(): void
    {
        config(['ai.pricing.openai.gpt-4o-mini' => ['input' => 0.01, 'output' => 0.03]]);
        Gate::define('view-attendance', fn ($user = null) => true);

        $observedMidLoop = null;

        Voice::registerAgent('usage-tools-bot', function ($agent) use (&$observedMidLoop) {
            $agent->stt('openai')->tts('openai')->llm('openai')->limits(maxTurns: 50, maxSessionSeconds: 3600)->tools([
                AuthorizedTool::make(
                    name: 'check_attendance',
                    description: "Check today's attendance",
                    parameters: ['type' => 'object', 'properties' => []],
                    ability: 'view-attendance',
                    // Invoked BETWEEN the tool-call step's own $onStep and
                    // the final-answer step's - reading the session fresh
                    // from the database here directly proves the first
                    // step's usage was already durably persisted by this
                    // point, not merely accumulated in memory.
                    handler: function (array $args) use (&$observedMidLoop) {
                        $observedMidLoop = \EasyAI\LaravelVoice\Models\VoiceSession::query()->first()->total_prompt_tokens;

                        return ['present' => 42];
                    },
                    tier: AuthorizedTool::TIER_READ,
                ),
            ]);
        });

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'How many students are here today?']),
            'api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'function' => ['name' => 'check_attendance', 'arguments' => '{}'],
                        ]],
                    ]]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                ])
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => '42 students are present today.']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $agent = Voice::agent('usage-tools-bot');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        // The tool ran between the two LLM steps - by that point, the
        // FIRST step's 100 prompt tokens must already be visible in the
        // database, proving persistence happened per-step, not only once
        // after the whole loop finished.
        $this->assertSame(100, $observedMidLoop);

        $session->refresh();
        $this->assertSame(150, $session->total_prompt_tokens);
        $this->assertSame(30, $session->total_completion_tokens);
    }

    public function test_recovery_response_shape_remains_backward_compatible(): void
    {
        $this->fakeChatCompletion('42 students are present today.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $this->makeStaleTurn($session, [
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'pending',
            'idempotency_key' => 'attempt-1',
        ]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $result = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        // A recovered turn is still just a plain VoiceTurn - the exact
        // same model type, with the exact same columns, that a normal
        // turn returns. No new field, no different shape.
        $this->assertInstanceOf(VoiceTurn::class, $result);
        $this->assertSame('completed', $result->status);
    }

    /**
     * Deep-audit fix: reclaim()'s atomic UPDATE only protects the reclaim
     * decision itself - it does nothing to stop the ORIGINAL execution
     * (still holding its own, now-stale in-memory $userTurn) from simply
     * continuing to run and writing to the same row once another
     * execution has reclaimed it. Simulates the original execution being
     * slow, not dead: the reclaim happens (a direct DB write, out from
     * under this in-process handleTurn() call, the same technique used
     * elsewhere in this suite to simulate a second writer) WHILE this
     * execution's own STT call is still in flight. The original execution
     * must detect this and stop, rather than overwrite the reclaimed row
     * or charge usage for work whose result was discarded.
     */
    public function test_ownership_lost_during_stt_prevents_the_stale_execution_from_completing_the_turn(): void
    {
        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => function () use ($session) {
                DB::table('voice_turns')
                    ->where('voice_session_id', $session->id)
                    ->where('speaker', 'user')
                    ->update(['updated_at' => now()->addMinute()]);

                return Http::response(['text' => 'a transcript for an attempt that has already lost ownership']);
            },
            // Faked so an unfixed implementation that wrongly keeps going
            // still resolves through fakes rather than hitting a real
            // network call - the assertions below are what actually prove
            // the defect either way.
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'unreachable if ownership is correctly checked']]],
                'usage' => ['prompt_tokens' => 999, 'completion_tokens' => 999],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $this->expectException(TurnOwnershipLostException::class);

            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $userTurn = VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'user')->first();
        $this->assertNotSame('a transcript for an attempt that has already lost ownership', $userTurn->transcript);
        $this->assertNull(VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'assistant')->first());

        $session->refresh();
        $this->assertSame(0, $session->total_stt_ms);
    }

    /**
     * Same defect, later in the pipeline: ownership is lost mid-LLM-loop,
     * after STT already succeeded. The step whose usage would be credited
     * AFTER ownership was already lost must not be credited, and the loop
     * must stop rather than run a second step or reach TTS.
     */
    public function test_ownership_lost_mid_llm_loop_prevents_that_steps_usage_from_being_credited(): void
    {
        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'How many students are here today?']),
            'api.openai.com/v1/chat/completions' => function () use ($session) {
                DB::table('voice_turns')
                    ->where('voice_session_id', $session->id)
                    ->where('speaker', 'assistant')
                    ->update(['updated_at' => now()->addMinute()]);

                return Http::response([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => 'a reply from an attempt that has already lost ownership']]],
                    'usage' => ['prompt_tokens' => 999, 'completion_tokens' => 999],
                ]);
            },
            // Faked so an unfixed implementation that wrongly keeps going
            // still resolves through a fake rather than a real network call.
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $this->expectException(TurnOwnershipLostException::class);

            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $session->refresh();
        $this->assertSame(0, $session->total_prompt_tokens, 'A step whose ownership check failed must not credit its usage to the session.');
        $this->assertSame(0, $session->total_completion_tokens);

        // Exactly the STT call plus the one chat/completions call that
        // discovered the ownership loss - no retry, no second LLM step,
        // TTS never reached.
        Http::assertSentCount(2);
    }

    /**
     * Deep-audit Fix 2: a completed user turn whose assistant turn was
     * never created (the owning process died between the two writes) is
     * currently unconditionally treated as "still processing" - 409
     * forever, with no staleness check at all. A FRESH row in this state
     * (STT genuinely just finished a moment ago) must still 409 - this is
     * unchanged, existing Phase 9B behavior, reconfirmed here.
     */
    public function test_a_fresh_completed_user_turn_with_no_assistant_turn_yet_still_returns_409(): void
    {
        Http::fake(); // any outbound call at all fails the assertion below

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'completed',
            'transcript' => 'How many students are here today?',
            'idempotency_key' => 'attempt-1',
        ]);
        // Deliberately no assistant turn - fresh updated_at (just now),
        // well within the 60s threshold configured in setUp().

        $audioPath = $this->makeTempAudioFile();

        try {
            $this->expectException(VoiceException::class);
            $this->expectExceptionMessage('still processing');

            $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        Http::assertNothingSent();
        $this->assertSame(0, VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'assistant')->count());
    }

    /**
     * The same state, but stale: the process that completed STT died
     * before it ever created the assistant turn. This must now recover -
     * STT is not repeated (the persisted transcript is reused), and
     * exactly one assistant turn is created and completed.
     */
    public function test_a_stale_completed_user_turn_with_no_assistant_turn_recovers_without_repeating_stt(): void
    {
        Event::fake();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => '42 students are present today.']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            // Deliberately no fake for audio/transcriptions.
        ]);

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $userTurn = $this->makeStaleTurn($session, [
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'completed',
            'transcript' => 'How many students are here today?',
            'idempotency_key' => 'attempt-1',
        ]);
        // Deliberately no assistant turn.

        $audioPath = $this->makeTempAudioFile();

        try {
            $result = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('42 students are present today.', $result->transcript);
        $this->assertSame('completed', $result->status);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'audio/transcriptions'));

        $assistantTurns = VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'assistant')->get();
        $this->assertCount(1, $assistantTurns);
        $this->assertSame(2, $assistantTurns->first()->sequence);
        $this->assertSame($userTurn->sequence, 1);

        Event::assertDispatched(TurnRecovered::class, fn (TurnRecovered $e) => $e->stage === 'llm');
    }

    /**
     * Not a real concurrent-process proof (this is a single-process
     * SQLite suite - see this file's own class docblock and reclaim()'s)
     * - a deterministic proof that the DB-level property is correct: two
     * sequential attempts to recover the same stale "completed user,
     * missing assistant" state, exactly as claimNextTurn()'s own
     * session-row lock would serialize two real concurrent callers, must
     * not both create an assistant turn for it.
     */
    public function test_two_recovery_attempts_for_a_stale_completed_user_missing_assistant_do_not_both_create_an_assistant_turn(): void
    {
        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $userTurn = $this->makeStaleTurn($session, [
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'completed',
            'transcript' => 'Hello',
            'idempotency_key' => 'attempt-1',
        ]);

        $resolve = new \ReflectionMethod($agent, 'resolveExistingClaim');
        $resolve->setAccessible(true);

        $first = $resolve->invoke($agent, $session, $userTurn->fresh());
        $second = $resolve->invoke($agent, $session, $userTurn->fresh());

        $this->assertSame('recover_assistant_turn', $first['outcome']);
        $this->assertSame('duplicate_pending', $second['outcome']);

        $this->assertSame(1, VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'assistant')->count());
    }

    /**
     * Deep-audit follow-up: the narrow, pre-existing edge case this
     * branch's own defensive catch exists for - an UNRELATED turn (a
     * different key, created normally while this one sat stuck) already
     * occupies the exact sequence number this recovery needs for its
     * assistant turn. The (voice_session_id, sequence) unique index
     * rejects the second insert with a real constraint-violation
     * QueryException; this must be reported as a conflict, not an
     * uncaught 500.
     */
    public function test_a_real_sequence_conflict_during_missing_assistant_recovery_falls_back_to_conflict(): void
    {
        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $userTurn = $this->makeStaleTurn($session, [
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'completed',
            'transcript' => 'Hello',
            'idempotency_key' => 'attempt-1',
        ]);

        // An unrelated, independently-created turn already claimed
        // sequence 2 (the exact slot this recovery would need for its
        // assistant turn) - a genuinely different logical exchange, not
        // part of this recovery attempt at all.
        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 2,
            'speaker' => 'user',
            'status' => 'completed',
            'idempotency_key' => 'unrelated-attempt',
        ]);

        $resolve = new \ReflectionMethod($agent, 'resolveExistingClaim');
        $resolve->setAccessible(true);

        $result = $resolve->invoke($agent, $session, $userTurn->fresh());

        $this->assertSame('duplicate_pending', $result['outcome']);

        // No assistant turn was created for the stuck key - the create()
        // call failed on the real unique-index collision and nothing was
        // left half-written.
        $this->assertSame(0, VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'assistant')->count());
    }
}
