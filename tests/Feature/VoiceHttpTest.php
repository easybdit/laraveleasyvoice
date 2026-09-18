<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Tests\Support\FakeUser;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VoiceHttpTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Routes stay off in the base TestCase (secure default) - this
        // subclass is the one place that opts in, with 'auth' still in
        // place, to exercise the authenticated HTTP surface.
        $app['config']->set('voice.routes.enabled', true);
        $app['config']->set('voice.routes.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Voice::registerAgent('receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')->limits(50, 3600);
        });
    }

    protected function actingAsFakeUser(int $id = 7): FakeUser
    {
        $user = new FakeUser($id);
        $this->actingAs($user);

        return $user;
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/voice/sessions', ['agent' => 'receptionist'])
            ->assertStatus(401);
    }

    public function test_it_creates_a_session_for_an_authenticated_user(): void
    {
        $this->actingAsFakeUser(7);

        $response = $this->postJson('/voice/sessions', ['agent' => 'receptionist']);

        $response->assertStatus(201)->assertJsonPath('agent', 'receptionist');

        $this->assertDatabaseHas('voice_sessions', [
            'id' => $response->json('id'),
            'user_id' => 7,
        ]);
    }

    public function test_an_unregistered_agent_returns_404(): void
    {
        $this->actingAsFakeUser(7);

        $this->postJson('/voice/sessions', ['agent' => 'does-not-exist'])
            ->assertStatus(404);
    }

    public function test_a_full_turn_over_http_returns_a_transcript_and_a_private_audio_url(): void
    {
        $this->actingAsFakeUser(7);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello there']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hi! How can I help?']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');

        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        $response = $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio]);

        $response->assertOk()
            ->assertJsonPath('transcript', 'Hi! How can I help?')
            ->assertJsonStructure(['audio_url']);

        $this->get($response->json('audio_url'))->assertOk();
    }

    public function test_the_stt_provider_receives_a_real_audio_extension_not_the_bare_php_tmp_path(): void
    {
        // Regression test: UploadedFile::getRealPath() is PHP's raw upload
        // tmp file (e.g. "phpXXXX.tmp" on this OS, often extensionless on
        // others) - OpenAiSttProvider names its multipart file after
        // basename($audioFilePath), so handing it that raw tmp path directly
        // silently broke STT against at least one real provider ("Unsupported
        // or corrupted audio format", confirmed live against Together AI's
        // Whisper endpoint) despite the bytes being a perfectly valid upload.
        // VoiceTurnController::store() now copies to a path carrying the
        // real, already-validated extension before calling handleTurn().
        $this->actingAsFakeUser(7);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello there']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hi!']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');
        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'audio/transcriptions')) {
                return false;
            }

            $body = (string) $request->body();

            return str_contains($body, '.mp3"') && ! str_contains($body, '.tmp"');
        });
    }

    public function test_an_llm_provider_failure_returns_a_generic_502_without_leaking_the_real_error(): void
    {
        // Regression test: AI::provider()->run() throws LaravelEasyAI's OWN
        // exception types (EasyAI\LaravelAI\Exceptions\*), not this
        // package's - found live when an unreachable LLM backend during the
        // LLM step of a turn produced a raw, unwrapped stack trace at the
        // HTTP layer instead of the same generic 502 STT/TTS failures
        // already get, since VoiceTurnController's catch clause only
        // matches EasyAI\LaravelVoice\Exceptions\*. VoiceAgent::handleTurn()
        // now re-wraps an LLM-step failure into this package's own exception
        // types before it leaves the method.
        $this->actingAsFakeUser(7);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello there']),
            'api.openai.com/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'upstream-secret-detail-should-not-leak']],
                500
            ),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');
        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        $response = $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio]);

        $response->assertStatus(502);
        $this->assertStringNotContainsString('upstream-secret-detail-should-not-leak', $response->getContent());
    }

    public function test_an_oversized_upload_is_rejected_before_any_provider_call(): void
    {
        $this->actingAsFakeUser(7);

        Http::fake(); // any outbound call at all fails the test below

        config(['voice.routes.max_upload_kb' => 1]); // 1 KB ceiling

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');

        $audio = UploadedFile::fake()->create('speech.mp3', 50, 'audio/mpeg'); // 50 KB

        // A validation failure on a plain post() redirects (302) unless the
        // request declares it wants JSON - explicit here since file uploads
        // can't go through postJson()'s json-encoded body.
        $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_user_cannot_end_another_users_session(): void
    {
        $this->actingAsFakeUser(1);
        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');

        $this->actingAsFakeUser(2);

        $this->postJson("/voice/sessions/{$sessionId}/end", [])
            ->assertStatus(403);
    }

    public function test_a_user_cannot_play_another_users_turn_audio(): void
    {
        $this->actingAsFakeUser(1);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hi']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hello.']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');
        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');
        $audioUrl = $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio])->json('audio_url');

        $this->actingAsFakeUser(2);

        $this->get($audioUrl)->assertStatus(403);
    }
}
