<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Agent\AuthorizedTool;
use EasyAI\LaravelVoice\Events\ResponseSynthesized;
use EasyAI\LaravelVoice\Events\SessionEnded;
use EasyAI\LaravelVoice\Events\SessionStarted;
use EasyAI\LaravelVoice\Events\SpeechTranscribed;
use EasyAI\LaravelVoice\Events\ToolCallCompleted;
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

        Event::assertDispatched(ToolCallCompleted::class, function (ToolCallCompleted $event) {
            return $event->tool === 'check_attendance' && $event->result === ['present' => 42];
        });
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
}
