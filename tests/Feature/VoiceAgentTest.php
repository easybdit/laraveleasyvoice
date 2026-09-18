<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Agent\AuthorizedTool;
use EasyAI\LaravelVoice\Events\ResponseChunkReceived;
use EasyAI\LaravelVoice\Events\ResponseSynthesized;
use EasyAI\LaravelVoice\Events\SessionEnded;
use EasyAI\LaravelVoice\Events\SessionStarted;
use EasyAI\LaravelVoice\Events\SpeechTranscribed;
use EasyAI\LaravelVoice\Events\ToolCallCompleted;
use EasyAI\LaravelVoice\Events\ToolCallStarted;
use EasyAI\LaravelVoice\Exceptions\VoiceLimitExceededException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Models\VoiceTurn;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VoiceAgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Voice::registerAgent('receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')
                ->systemPrompt('You are a helpful receptionist.')
                ->limits(maxTurns: 2, maxSessionSeconds: 3600);
        });
    }

    protected function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.mp3';
        file_put_contents($path, 'fake-audio-bytes');

        return $path;
    }

    protected function fakeChatCompletion(string $content, array $toolCalls = []): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'What is the admission policy?']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [[
                    'message' => array_filter([
                        'content' => $content,
                        'tool_calls' => $toolCalls ?: null,
                    ]),
                ]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);
    }

    public function test_it_runs_a_full_voice_turn_and_persists_both_sides(): void
    {
        Event::fake();
        $this->fakeChatCompletion('The admission policy is open enrollment.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $this->assertSame('active', $session->status);

        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('assistant', $assistantTurn->speaker);
        $this->assertSame('The admission policy is open enrollment.', $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);
        $this->assertNotNull($assistantTurn->audio_path);

        Storage::disk('local')->assertExists($assistantTurn->audio_path);

        $userTurn = $session->turns()->where('speaker', 'user')->first();
        $this->assertSame('What is the admission policy?', $userTurn->transcript);

        $session->refresh();
        $this->assertSame(12, $session->total_prompt_tokens);
        $this->assertSame(8, $session->total_completion_tokens);

        Event::assertDispatched(SessionStarted::class);
        Event::assertDispatched(SpeechTranscribed::class);
        Event::assertDispatched(ResponseSynthesized::class);
    }

    public function test_it_invokes_authorized_tools_through_the_agent_loop(): void
    {
        Event::fake();
        Gate::define('view-attendance', fn ($user = null) => true);

        Voice::registerAgent('school-bot', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')->tools([
                AuthorizedTool::make(
                    name: 'check_attendance',
                    description: "Check today's attendance",
                    parameters: ['type' => 'object', 'properties' => []],
                    ability: 'view-attendance',
                    handler: fn (array $args) => ['present' => 42],
                    tier: AuthorizedTool::TIER_READ,
                ),
            ]);
        });

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'How many students are here today?']),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            'api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [[
                        'message' => [
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'function' => ['name' => 'check_attendance', 'arguments' => '{}'],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => '42 students are present today.']]],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
                ]),
        ]);

        $agent = Voice::agent('school-bot');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('42 students are present today.', $assistantTurn->transcript);
        $this->assertNotEmpty($assistantTurn->tool_calls);
        $this->assertSame('check_attendance', $assistantTurn->tool_calls[0]['name']);
        $this->assertSame(AuthorizedTool::TIER_READ, $assistantTurn->tool_calls[0]['tier']);

        Event::assertDispatched(ToolCallCompleted::class, function (ToolCallCompleted $event) {
            return $event->tool === 'check_attendance'
                && $event->result === ['present' => 42]
                && $event->tier === AuthorizedTool::TIER_READ;
        });

        Event::assertDispatched(ToolCallStarted::class, fn (ToolCallStarted $event) => $event->tier === AuthorizedTool::TIER_READ);
    }

    public function test_it_ends_the_session_and_blocks_further_turns_once_the_limit_is_reached(): void
    {
        Event::fake();
        $this->fakeChatCompletion('Response one.');

        $agent = Voice::agent('receptionist'); // limit(maxTurns: 2, ...)
        $session = $agent->startSession(['user_id' => 1]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
            $agent->handleTurn($session, $audioPath);

            $this->expectException(VoiceLimitExceededException::class);
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $session->refresh();
        $this->assertSame('ended', $session->status);

        Event::assertDispatched(SessionEnded::class);
    }

    /**
     * Defense-in-depth for the sequence-locking fix in handleTurn(): the
     * voice_turns(voice_session_id, sequence) unique index (a new,
     * additive migration) must reject a second row that collides with an
     * already-persisted one for the same session, rather than allowing
     * corrupted/ambiguous turn ordering to be written silently. Tested
     * directly against the schema/constraint here, not by attempting real
     * concurrent requests - this single-process PHPUnit suite (running
     * against SQLite, which has no row-level locking) cannot faithfully
     * reproduce true concurrent locking behaviour; lockForUpdate()'s
     * actual serialization is a MySQL/MariaDB (InnoDB) production
     * property verified by code review, not by an automated test here.
     * This constraint is the layer that stays enforced even so.
     */
    public function test_the_database_rejects_a_second_turn_with_a_colliding_sequence_for_the_same_session(): void
    {
        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'completed',
        ]);

        $this->expectException(QueryException::class);

        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 1,
            'speaker' => 'assistant',
            'status' => 'completed',
        ]);
    }

    public function test_streaming_fires_a_chunk_event_per_delta_and_still_persists_the_full_reply(): void
    {
        Event::fake();

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Tell me a short greeting.']),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            'api.openai.com/v1/chat/completions' => Http::response(
                "data: {\"model\":\"gpt-4o-mini\",\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n".
                "data: {\"model\":\"gpt-4o-mini\",\"choices\":[{\"delta\":{\"content\":\" there!\"}}]}\n\n".
                "data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        Voice::registerAgent('streaming-bot', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')->streamResponses();
        });

        $agent = Voice::agent('streaming-bot');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('Hi there!', $assistantTurn->transcript);

        Event::assertDispatched(ResponseChunkReceived::class, fn (ResponseChunkReceived $e) => $e->chunk === 'Hi');
        Event::assertDispatched(ResponseChunkReceived::class, fn (ResponseChunkReceived $e) => $e->chunk === ' there!');
    }

    public function test_streaming_is_off_by_default_and_fires_no_chunk_events(): void
    {
        Event::fake();
        $this->fakeChatCompletion('A plain, non-streamed reply.');

        $agent = Voice::agent('receptionist'); // streamResponses() never called
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        Event::assertNotDispatched(ResponseChunkReceived::class);
    }

    /**
     * AbstractDriver::run() streams every step of the agent loop, not just
     * the final one (vendor/easybdit/laraveleasyai/src/Drivers/AbstractDriver.php
     * run()'s own docblock) - so a step that results in a tool call is also
     * streamed. OpenAIDriver::handleStream() only invokes the chunk callback
     * for delta.content, never for delta.tool_calls fragments - confirmed by
     * reading handleStream() directly, not assumed - so the tool-call step
     * is expected to fire zero ResponseChunkReceived events, and only the
     * subsequent final-answer step (after the tool result is fed back)
     * should stream real content chunks.
     */
    public function test_streaming_and_tools_together_only_emit_chunks_for_the_final_answer_step(): void
    {
        Event::fake();
        Gate::define('view-attendance', fn ($user = null) => true);

        Voice::registerAgent('streaming-tools-bot', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')->streamResponses()->tools([
                AuthorizedTool::make(
                    name: 'check_attendance',
                    description: "Check today's attendance",
                    parameters: ['type' => 'object', 'properties' => []],
                    ability: 'view-attendance',
                    handler: fn (array $args) => ['present' => 42],
                    tier: AuthorizedTool::TIER_READ,
                ),
            ]);
        });

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'How many students are here today?']),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            'api.openai.com/v1/chat/completions' => Http::sequence()
                // Step 1: the model asks for a tool call - delta.tool_calls
                // fragments only, no delta.content anywhere, mirroring
                // OpenAI's real wire format (id/name arrive complete in the
                // first delta, arguments arrive as a partial JSON string
                // split across deltas - see handleStream()'s own docblock).
                ->push(
                    "data: {\"model\":\"gpt-4o-mini\",\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_1\",\"function\":{\"name\":\"check_attendance\",\"arguments\":\"\"}}]}}]}\n\n".
                    "data: {\"model\":\"gpt-4o-mini\",\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"{}\"}}]}}]}\n\n".
                    "data: [DONE]\n\n",
                    200,
                    ['Content-Type' => 'text/event-stream']
                )
                // Step 2: the final answer, streamed as plain content
                // deltas - same fixture style as the streaming-only test
                // above.
                ->push(
                    "data: {\"model\":\"gpt-4o-mini\",\"choices\":[{\"delta\":{\"content\":\"42 students\"}}]}\n\n".
                    "data: {\"model\":\"gpt-4o-mini\",\"choices\":[{\"delta\":{\"content\":\" are present today.\"}}]}\n\n".
                    "data: [DONE]\n\n",
                    200,
                    ['Content-Type' => 'text/event-stream']
                ),
        ]);

        $agent = Voice::agent('streaming-tools-bot');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        // The tool actually executed.
        Event::assertDispatched(ToolCallCompleted::class, fn (ToolCallCompleted $e) => $e->tool === 'check_attendance' && $e->result === ['present' => 42]);

        // Chunks fired only for the final-answer step's two deltas - if the
        // tool-call step had also emitted any, this count would be higher.
        Event::assertDispatchedTimes(ResponseChunkReceived::class, 2);
        Event::assertDispatched(ResponseChunkReceived::class, fn (ResponseChunkReceived $e) => $e->chunk === '42 students');
        Event::assertDispatched(ResponseChunkReceived::class, fn (ResponseChunkReceived $e) => $e->chunk === ' are present today.');

        // Final transcript persisted, tool call persisted, turn completed, TTS ran.
        $this->assertSame('42 students are present today.', $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);
        $this->assertNotEmpty($assistantTurn->tool_calls);
        $this->assertSame('check_attendance', $assistantTurn->tool_calls[0]['name']);
        $this->assertSame(AuthorizedTool::TIER_READ, $assistantTurn->tool_calls[0]['tier']);
        $this->assertNotNull($assistantTurn->audio_path);
        Storage::disk('local')->assertExists($assistantTurn->audio_path);
    }

    public function test_it_tracks_stt_duration_and_estimated_cost_on_the_session(): void
    {
        config(['ai.pricing.openai.gpt-4o-mini' => ['input' => 0.01, 'output' => 0.03]]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'What is the admission policy?',
                'duration' => 3.2,
            ]),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Open enrollment.']]],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 1000],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $session->refresh();
        $this->assertSame(3200, $session->total_stt_ms);
        // 1000 prompt tokens @ $0.01/1k + 1000 completion tokens @ $0.03/1k
        $this->assertEqualsWithDelta(0.04, $session->estimated_cost, 0.0001);
    }

    /**
     * accumulateCost() must never lose a cost contribution written
     * directly to the database in between this PHP process's own read and
     * write - the exact shape of a real concurrent-turn lost-update race,
     * reproduced deterministically in a single process by writing to the
     * row out from under the in-memory $session object (which is never
     * refreshed here), simulating "another turn already committed its own
     * contribution a moment ago." The old `$session->update([
     * 'estimated_cost' => ($session->estimated_cost ?? 0) + $cost])`
     * would read this test's still-null in-memory attribute and overwrite
     * the injected value entirely; the atomic `COALESCE(estimated_cost,
     * 0) + ?` update reads the row's real current value at write time
     * regardless of what the PHP object thinks it is.
     */
    public function test_estimated_cost_accumulation_never_loses_a_value_written_concurrently_by_another_process(): void
    {
        config(['ai.pricing.openai.gpt-4o-mini' => ['input' => 0.01, 'output' => 0.03]]);

        $this->fakeChatCompletion('Open enrollment.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        // Simulate a concurrent turn's contribution landing in the
        // database - deliberately bypassing $session (its in-memory
        // estimated_cost attribute stays null, exactly as a stale read
        // would in a real race).
        DB::table('voice_sessions')->where('id', $session->id)->update(['estimated_cost' => 0.05]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        // fakeChatCompletion() reports usage of 12 prompt / 8 completion
        // tokens (see that helper below): 12/1000*0.01 + 8/1000*0.03 =
        // 0.00012 + 0.00024 = 0.00036. The "concurrently written" 0.05
        // must still be present in the total, not silently discarded.
        $session->refresh();
        $this->assertEqualsWithDelta(0.05036, $session->estimated_cost, 0.000001);
    }

    /**
     * AbstractDriver::run() (vendor/easybdit/laraveleasyai/src/Drivers/
     * AbstractDriver.php) reassigns $response on every loop iteration and
     * only returns the LAST step's AIResponse - a tool-calling turn makes
     * two real LLM calls (the tool-call decision, then the final answer
     * after the tool result is fed back), each with its own real usage.
     * handleTurn() reads getPromptTokens()/getCompletionTokens()/
     * getEstimatedCost() only off that single final $response - this test
     * checks whether the session's running totals reflect BOTH calls'
     * usage or only the last one's.
     */
    public function test_it_accumulates_llm_usage_across_every_tool_calling_step_not_just_the_final_one(): void
    {
        config(['ai.pricing.openai.gpt-4o-mini' => ['input' => 0.01, 'output' => 0.03]]);
        Gate::define('view-attendance', fn ($user = null) => true);

        Voice::registerAgent('usage-tools-bot', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')->tools([
                AuthorizedTool::make(
                    name: 'check_attendance',
                    description: "Check today's attendance",
                    parameters: ['type' => 'object', 'properties' => []],
                    ability: 'view-attendance',
                    handler: fn (array $args) => ['present' => 42],
                    tier: AuthorizedTool::TIER_READ,
                ),
            ]);
        });

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'How many students are here today?']),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            'api.openai.com/v1/chat/completions' => Http::sequence()
                // Step 1: the tool-call decision - its own real usage,
                // distinct from step 2's, spent deciding to call the tool.
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
                // Step 2: the final answer, after the tool result is fed
                // back - its own distinct usage.
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => '42 students are present today.']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                ]),
        ]);

        $agent = Voice::agent('usage-tools-bot');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $session->refresh();

        // Two real LLM calls were made for this one turn - the session's
        // running totals should reflect BOTH, not just whichever one
        // AbstractDriver::run() happens to return.
        $this->assertSame(150, $session->total_prompt_tokens, 'Expected prompt tokens summed across both agent-loop steps (100 + 50).');
        $this->assertSame(30, $session->total_completion_tokens, 'Expected completion tokens summed across both agent-loop steps (20 + 10).');

        // Step 1: 100 prompt @ $0.01/1k + 20 completion @ $0.03/1k = 0.0016
        // Step 2: 50 prompt @ $0.01/1k + 10 completion @ $0.03/1k = 0.0008
        // Expected total: 0.0024
        $this->assertEqualsWithDelta(0.0024, $session->estimated_cost, 0.0001, 'Expected cost summed across both agent-loop steps.');
    }

    public function test_it_mirrors_turns_into_a_linked_chat_session(): void
    {
        $chatSession = \EasyAI\LaravelAI\Chat\Models\ChatSession::create(['title' => 'Linked chat']);

        $this->fakeChatCompletion('The admission policy is open enrollment.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1, 'chat_session_id' => $chatSession->id]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $this->assertDatabaseHas('ai_chat_messages', [
            'chat_session_id' => $chatSession->id,
            'role' => 'user',
            'content' => 'What is the admission policy?',
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'chat_session_id' => $chatSession->id,
            'role' => 'assistant',
            'content' => 'The admission policy is open enrollment.',
        ]);
    }

    public function test_it_does_not_mirror_when_no_chat_session_is_linked(): void
    {
        $this->fakeChatCompletion('Some reply.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $this->assertDatabaseCount('ai_chat_messages', 0);
    }
}
