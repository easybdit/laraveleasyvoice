<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Agent\Tools\RecallFactTool;
use EasyAI\LaravelVoice\Agent\Tools\RememberFactTool;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Support\CurrentVoiceSession;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VoiceMemoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('voice-remember-fact', fn ($user = null) => true);
        Gate::define('voice-recall-fact', fn ($user = null) => true);

        Voice::registerAgent('memory-bot', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')->tools([
                RememberFactTool::make(),
                RecallFactTool::make(),
            ]);
        });
    }

    protected function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.mp3';
        file_put_contents($path, 'fake-audio-bytes');

        return $path;
    }

    public function test_a_fact_remembered_in_one_session_is_recalled_in_a_later_session_for_the_same_caller(): void
    {
        $agent = Voice::agent('memory-bot');

        $firstCall = $agent->startSession(['user_id' => 1]);
        CurrentVoiceSession::set($firstCall);
        RememberFactTool::make()->execute(['key' => 'name', 'value' => 'Murad']);
        CurrentVoiceSession::clear();

        // A brand new session - same user, no relationship to the first
        // session row at all - proves the fact survived the session
        // boundary, not just the request.
        $secondCall = $agent->startSession(['user_id' => 1]);
        CurrentVoiceSession::set($secondCall);
        $result = RecallFactTool::make()->execute(['key' => 'name']);
        CurrentVoiceSession::clear();

        $this->assertSame(['found' => true, 'value' => 'Murad'], $result);
    }

    public function test_recalling_an_unset_fact_reports_not_found(): void
    {
        $session = Voice::agent('memory-bot')->startSession(['user_id' => 1]);
        CurrentVoiceSession::set($session);

        $result = RecallFactTool::make()->execute(['key' => 'favorite_color']);

        $this->assertSame(['found' => false], $result);
    }

    public function test_a_different_user_cannot_recall_another_users_remembered_fact(): void
    {
        $agent = Voice::agent('memory-bot');

        $sessionForUserOne = $agent->startSession(['user_id' => 1]);
        CurrentVoiceSession::set($sessionForUserOne);
        RememberFactTool::make()->execute(['key' => 'name', 'value' => 'Murad']);
        CurrentVoiceSession::clear();

        $sessionForUserTwo = $agent->startSession(['user_id' => 2]);
        CurrentVoiceSession::set($sessionForUserTwo);
        $result = RecallFactTool::make()->execute(['key' => 'name']);

        $this->assertSame(['found' => false], $result);
    }

    public function test_remembering_fails_gracefully_for_a_session_with_no_identity(): void
    {
        $session = Voice::agent('memory-bot')->startSession([]); // no user_id, no guest_token, no tenant_id
        CurrentVoiceSession::set($session);

        $result = RememberFactTool::make()->execute(['key' => 'name', 'value' => 'Murad']);

        $this->assertArrayHasKey('error', $result);
        $this->assertDatabaseCount('voice_memories', 0);
    }

    public function test_it_denies_recall_when_the_ability_gate_is_not_granted(): void
    {
        Gate::define('voice-recall-fact', fn ($user = null) => false);

        $session = Voice::agent('memory-bot')->startSession(['user_id' => 1]);
        CurrentVoiceSession::set($session);

        $result = RecallFactTool::make()->execute(['key' => 'name']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Not authorized', $result['error']);
    }

    public function test_the_agent_loop_can_remember_a_fact_through_a_real_tool_call(): void
    {
        Storage::fake('local');

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'My name is Murad.']),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio', 200, ['Content-Type' => 'audio/mpeg']),
            'api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'function' => ['name' => 'remember_fact', 'arguments' => '{"key":"name","value":"Murad"}'],
                        ]],
                    ]]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => 'Nice to meet you, Murad.']]],
                    'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 8],
                ]),
        ]);

        $agent = Voice::agent('memory-bot');
        $session = $agent->startSession(['user_id' => 42]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $turn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('Nice to meet you, Murad.', $turn->transcript);
        $this->assertDatabaseHas('voice_memories', [
            'user_id' => 42,
            'key' => 'name',
            'value' => 'Murad',
        ]);

        // The ambient binding must not leak past the turn it was set for.
        $this->assertNull(CurrentVoiceSession::get());
    }
}
