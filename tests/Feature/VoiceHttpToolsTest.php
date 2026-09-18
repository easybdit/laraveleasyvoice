<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Agent\AuthorizedTool;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Tests\Support\FakeUser;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Every existing tool-calling test (VoiceAgentTest, VoiceAgentDeepgramToolsTest)
 * calls VoiceAgent::handleTurn() directly - none go through the actual
 * POST /voice/sessions/{id}/turns HTTP endpoint. This proves tool_calls
 * survives VoiceTurnController::store()'s JSON response under real
 * auth/ownership middleware, not just at the orchestrator level.
 */
class VoiceHttpToolsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.routes.enabled', true);
        $app['config']->set('voice.routes.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function actingAsFakeUser(int $id = 7): FakeUser
    {
        $user = new FakeUser($id);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_tool_call_over_http_is_executed_and_reflected_in_the_turn_response(): void
    {
        Gate::define('view-attendance', fn ($user = null) => true);

        Voice::registerAgent('school-attendance-http', function ($agent) {
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

        $this->actingAsFakeUser(7);

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
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => '42 students are present today.']]],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
                ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'school-attendance-http'])->json('id');

        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        $response = $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio]);

        $response->assertOk()
            ->assertJsonPath('transcript', '42 students are present today.')
            ->assertJsonPath('tool_calls.0.name', 'check_attendance')
            ->assertJsonPath('tool_calls.0.tier', AuthorizedTool::TIER_READ)
            ->assertJsonStructure(['audio_url']);

        $this->assertDatabaseHas('voice_turns', [
            'id' => $response->json('id'),
            'voice_session_id' => $sessionId,
            'speaker' => 'assistant',
            'transcript' => '42 students are present today.',
            'status' => 'completed',
        ]);

        $this->get($response->json('audio_url'))->assertOk();
    }

    public function test_a_gate_denied_tool_over_http_still_completes_the_turn_with_the_denial_recorded(): void
    {
        Gate::define('delete-student', fn ($user = null) => false);

        $handlerCalled = false;

        Voice::registerAgent('school-delete-http', function ($agent) use (&$handlerCalled) {
            $agent->stt('openai')->tts('openai')->llm('openai')->tools([
                AuthorizedTool::make(
                    name: 'delete_student',
                    description: 'Delete a student record',
                    parameters: ['type' => 'object', 'properties' => []],
                    ability: 'delete-student',
                    handler: function (array $args) use (&$handlerCalled) {
                        $handlerCalled = true;

                        return ['deleted' => true];
                    },
                    tier: AuthorizedTool::TIER_DESTRUCTIVE,
                ),
            ]);
        });

        $this->actingAsFakeUser(7);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Please delete student 42.']),
            'api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'function' => ['name' => 'delete_student', 'arguments' => '{}'],
                        ]],
                    ]]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => "I'm not able to do that."]]],
                    'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 8],
                ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'school-delete-http'])->json('id');

        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        $response = $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio]);

        $response->assertOk()
            ->assertJsonPath('transcript', "I'm not able to do that.")
            ->assertJsonPath('tool_calls.0.name', 'delete_student')
            ->assertJsonPath('tool_calls.0.tier', AuthorizedTool::TIER_DESTRUCTIVE);

        $this->assertFalse($handlerCalled, 'The tool handler must never execute when Gate::allows() denies the ability.');

        $this->assertDatabaseHas('voice_turns', [
            'id' => $response->json('id'),
            'voice_session_id' => $sessionId,
            'speaker' => 'assistant',
            'status' => 'completed',
        ]);
    }
}
