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
