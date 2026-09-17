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
use EasyAI\LaravelVoice\Tests\TestCase;
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
