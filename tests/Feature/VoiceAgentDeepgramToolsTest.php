<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Agent\AuthorizedTool;
use EasyAI\LaravelVoice\Events\ResponseSynthesized;
use EasyAI\LaravelVoice\Events\SpeechTranscribed;
use EasyAI\LaravelVoice\Events\ToolCallCompleted;
use EasyAI\LaravelVoice\Events\ToolCallStarted;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Proves the existing tool-calling pipeline (VoiceAgent -> LaravelEasyAI's
 * AbstractDriver::run() agent loop -> Tool::execute()) works unchanged with
 * stt('deepgram')/tts('deepgram')/llm('together') - the Phase 4 target
 * combination - and that two specific, source-verified behaviors already
 * documented in the Phase 4 audit hold true through the *full* VoiceAgent
 * pipeline, not just at the unit level where AuthorizedToolTest/
 * VoiceMemoryTest already exercise them: an authorization denial, and a
 * tool handler that throws. Both are LaravelEasyAI's own existing
 * behaviors (Tool::execute()'s catch-all, AuthorizedTool's Gate check) -
 * nothing here changes or patches either package.
 */
class VoiceAgentDeepgramToolsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.stt.providers.deepgram', [
            'api_key' => 'test-key',
            'url' => 'https://api.deepgram.com/v1',
            'model' => 'nova-2',
            'timeout' => 10,
            'max_file_size' => 25 * 1024 * 1024,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);

        $app['config']->set('voice.tts.providers.deepgram', [
            'api_key' => 'test-key',
            'url' => 'https://api.deepgram.com/v1',
            'model' => 'aura-2-thalia-en',
            'format' => 'mp3',
            'timeout' => 10,
            'max_input_length' => 2000,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);

        $app['config']->set('ai.providers.together', [
            'driver' => 'together',
            'api_key' => 'test-key',
            'url' => 'https://api.together.xyz/v1',
            'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
            'timeout' => 30,
            'options' => ['temperature' => 0.7, 'max_tokens' => 100],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.wav';
        file_put_contents($path, 'fake-audio-bytes');

        return $path;
    }

    public function test_full_pipeline_deepgram_stt_together_llm_tool_call_deepgram_tts(): void
    {
        Event::fake();
        Gate::define('view-attendance', fn ($user = null) => true);

        Voice::registerAgent('deepgram-together-tools', function ($agent) {
            $agent->stt('deepgram')->tts('deepgram')->llm('together')->tools([
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
            'api.deepgram.com/v1/listen*' => Http::response([
                'metadata' => ['duration' => 4.2],
                'results' => ['channels' => [['alternatives' => [['transcript' => 'How many students are here today?']]]]],
            ]),
            'api.together.xyz/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
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
                    'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
                    'choices' => [['message' => ['content' => '42 students are present today.']]],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
                ]),
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $agent = Voice::agent('deepgram-together-tools');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        // Deepgram STT was actually called, and the transcript it returned
        // was persisted on the user turn.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.deepgram.com/v1/listen'));

        $userTurn = $session->turns()->where('speaker', 'user')->first();
        $this->assertSame('How many students are here today?', $userTurn->transcript);
        $this->assertSame('completed', $userTurn->status);

        // Together received exactly that transcript as the new user message.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.together.xyz')) {
                return false;
            }

            return collect($request['messages'] ?? [])
                ->contains(fn ($m) => ($m['role'] ?? null) === 'user' && ($m['content'] ?? null) === 'How many students are here today?');
        });

        // The tool call was executed and its call (name/arguments/tier) persisted.
        $this->assertNotEmpty($assistantTurn->tool_calls);
        $this->assertSame('check_attendance', $assistantTurn->tool_calls[0]['name']);
        $this->assertSame(AuthorizedTool::TIER_READ, $assistantTurn->tool_calls[0]['tier']);

        // Together's final response (after the tool result was fed back)
        // was persisted as the assistant turn's transcript.
        $this->assertSame('42 students are present today.', $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);

        // Deepgram TTS received exactly that final response text.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.deepgram.com/v1/speak')) {
                return false;
            }

            return ($request['text'] ?? null) === '42 students are present today.';
        });

        $this->assertNotNull($assistantTurn->audio_path);
        Storage::disk('local')->assertExists($assistantTurn->audio_path);

        Event::assertDispatched(ToolCallStarted::class, fn (ToolCallStarted $e) => $e->tool === 'check_attendance' && $e->tier === AuthorizedTool::TIER_READ);
        Event::assertDispatched(ToolCallCompleted::class, fn (ToolCallCompleted $e) => $e->tool === 'check_attendance' && $e->result === ['present' => 42]);
        Event::assertDispatched(SpeechTranscribed::class);
        Event::assertDispatched(ResponseSynthesized::class);
    }

    public function test_authorization_denial_propagates_through_the_full_pipeline_and_the_turn_still_completes(): void
    {
        Event::fake();
        Gate::define('delete-student', fn ($user = null) => false);

        $handlerCalled = false;

        Voice::registerAgent('deepgram-together-denied-tool', function ($agent) use (&$handlerCalled) {
            $agent->stt('deepgram')->tts('deepgram')->llm('together')->tools([
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

        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response([
                'results' => ['channels' => [['alternatives' => [['transcript' => 'Please delete student 42.']]]]],
            ]),
            'api.together.xyz/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
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
                    'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
                    'choices' => [['message' => ['content' => "I'm not able to do that."]]],
                    'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 8],
                ]),
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $agent = Voice::agent('deepgram-together-denied-tool');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        // AuthorizedTool::make()'s Gate::allows() check (src/Agent/AuthorizedTool.php)
        // must deny before the handler ever runs.
        $this->assertFalse($handlerCalled, 'The tool handler must never execute when Gate::allows() denies the ability.');

        // The denial is returned through LaravelEasyAI's existing tool
        // mechanism (Tool::execute() -> the model sees the AuthorizedTool
        // closure's own ['error' => "Not authorized..."] result, the same
        // shape AuthorizedToolTest/VoiceMemoryTest already prove at the
        // unit level) - verified here via the event actually fired during
        // a full handleTurn() call, not assumed.
        Event::assertDispatched(ToolCallCompleted::class, function (ToolCallCompleted $e) {
            return $e->tool === 'delete_student'
                && is_array($e->result)
                && array_key_exists('error', $e->result)
                && str_contains($e->result['error'], 'Not authorized');
        });

        // The turn completes normally - Together's mocked final response
        // (after seeing the tool's denial result) is persisted, and TTS
        // still runs.
        $this->assertSame("I'm not able to do that.", $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);
        $this->assertNotEmpty($assistantTurn->tool_calls);
        $this->assertSame('delete_student', $assistantTurn->tool_calls[0]['name']);
        $this->assertNotNull($assistantTurn->audio_path);
        Storage::disk('local')->assertExists($assistantTurn->audio_path);
    }

    public function test_a_tool_handler_exception_becomes_an_error_result_and_the_turn_still_completes(): void
    {
        Event::fake();
        Gate::define('check-weather', fn ($user = null) => true);

        Voice::registerAgent('deepgram-together-throwing-tool', function ($agent) {
            $agent->stt('deepgram')->tts('deepgram')->llm('together')->tools([
                AuthorizedTool::make(
                    name: 'check_weather',
                    description: 'Check the current weather',
                    parameters: ['type' => 'object', 'properties' => []],
                    ability: 'check-weather',
                    handler: function (array $args) {
                        throw new \RuntimeException('Weather service unreachable');
                    },
                ),
            ]);
        });

        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response([
                'results' => ['channels' => [['alternatives' => [['transcript' => "What's the weather like?"]]]]],
            ]),
            'api.together.xyz/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
                    'choices' => [['message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'function' => ['name' => 'check_weather', 'arguments' => '{}'],
                        ]],
                    ]]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
                    'choices' => [['message' => ['content' => "I couldn't check the weather right now."]]],
                    'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 8],
                ]),
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $agent = Voice::agent('deepgram-together-throwing-tool');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        // LaravelEasyAI's own Tool::execute() (vendor/easybdit/laraveleasyai/
        // src/Agent/Tool.php) catches the thrown exception and converts it
        // into ['error' => $e->getMessage()] - verified here via the actual
        // event payload from a real thrown exception, not assumed. Neither
        // LaravelEasyAI nor this package's production source is modified.
        Event::assertDispatched(ToolCallCompleted::class, function (ToolCallCompleted $e) {
            return $e->tool === 'check_weather'
                && $e->result === ['error' => 'Weather service unreachable'];
        });

        // The exception never reaches VoiceAgent::handleTurn() at all - the
        // turn completes normally, exactly like the denial case above.
        $this->assertSame("I couldn't check the weather right now.", $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);
        $this->assertNull($assistantTurn->error_message);
        $this->assertNotEmpty($assistantTurn->tool_calls);
        $this->assertSame('check_weather', $assistantTurn->tool_calls[0]['name']);
        $this->assertNotNull($assistantTurn->audio_path);
        Storage::disk('local')->assertExists($assistantTurn->audio_path);
    }
}
