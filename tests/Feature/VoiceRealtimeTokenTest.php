<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Tests\Support\FakeUser;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class VoiceRealtimeTokenTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.realtime.enabled', true);
        $app['config']->set('voice.routes.middleware', ['web', 'auth']);
        $app['config']->set('voice.realtime.providers.openai', [
            'api_key' => 'test-key',
            'url' => 'https://api.openai.com/v1',
            'model' => 'gpt-realtime',
            'voice' => 'alloy',
            'timeout' => 10,
        ]);
        $app['config']->set('voice.realtime.providers.deepgram', [
            'api_key' => 'test-key',
            'url' => 'https://api.deepgram.com/v1',
            'project_id' => null,
            'ttl_seconds' => 3600,
            'timeout' => 10,
        ]);
    }

    protected function actingAsFakeUser(int $id = 1): FakeUser
    {
        $user = new FakeUser($id);
        $this->actingAs($user);

        return $user;
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/voice/realtime/token')->assertStatus(401);
    }

    public function test_it_mints_an_ephemeral_token_for_an_authenticated_user(): void
    {
        $this->actingAsFakeUser();

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'value' => 'ek_test_12345',
                'expires_at' => 1234567890,
                'session' => ['model' => 'gpt-realtime'],
            ]),
        ]);

        $response = $this->postJson('/voice/realtime/token');

        $response->assertOk()
            ->assertJsonPath('token', 'ek_test_12345')
            ->assertJsonPath('expires_at', 1234567890)
            ->assertJsonPath('model', 'gpt-realtime')
            ->assertJsonPath('voice', 'alloy')
            ->assertJsonPath('provider', 'openai');

        // This exact shape (session.type, and voice nested under
        // session.audio.output.voice rather than a flat session.voice)
        // was corrected against OpenAI's real, live API, not assumed -
        // see OpenAiRealtimeTokenBroker's own docblock.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'realtime/client_secrets')
                && $request['session']['type'] === 'realtime'
                && $request['session']['model'] === 'gpt-realtime'
                && $request['session']['audio']['output']['voice'] === 'alloy';
        });
    }

    public function test_it_accepts_a_custom_model_and_voice(): void
    {
        $this->actingAsFakeUser();

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'value' => 'ek_test_67890',
                'expires_at' => null,
            ]),
        ]);

        $this->postJson('/voice/realtime/token', ['voice' => 'verse'])
            ->assertOk()
            ->assertJsonPath('voice', 'verse');

        Http::assertSent(fn ($request) => $request['session']['audio']['output']['voice'] === 'verse');
    }

    public function test_a_provider_failure_returns_a_generic_502_without_leaking_the_real_error(): void
    {
        $this->actingAsFakeUser();

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response(['error' => ['message' => 'invalid_api_key: sk-secret-leak']], 401),
        ]);

        $response = $this->postJson('/voice/realtime/token');

        $response->assertStatus(502);
        $this->assertStringNotContainsString('sk-secret-leak', $response->getContent());
    }

    public function test_it_rejects_an_unsupported_provider_without_attempting_a_request(): void
    {
        $this->actingAsFakeUser();

        Http::fake();

        $this->postJson('/voice/realtime/token', ['provider' => 'together'])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Unsupported realtime provider: together. Supported: openai, deepgram.']);

        Http::assertNothingSent();
    }

    public function test_it_defaults_to_the_configured_provider(): void
    {
        $this->actingAsFakeUser();
        config(['voice.realtime.default' => 'openai']);

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_default', 'expires_at' => null]),
        ]);

        $this->postJson('/voice/realtime/token')
            ->assertOk()
            ->assertJsonPath('provider', 'openai');
    }

    public function test_it_mints_a_deepgram_ephemeral_key_resolving_project_id_automatically(): void
    {
        $this->actingAsFakeUser();

        Http::fake([
            'api.deepgram.com/v1/projects' => Http::response([
                'projects' => [['project_id' => 'proj-123', 'name' => 'Default Project']],
            ]),
            'api.deepgram.com/v1/projects/proj-123/keys' => Http::response([
                'key' => 'dg_scoped_test_key',
                'api_key_id' => 'key-abc',
            ]),
        ]);

        $response = $this->postJson('/voice/realtime/token', ['provider' => 'deepgram']);

        $response->assertOk()
            ->assertJsonPath('token', 'dg_scoped_test_key')
            ->assertJsonPath('project_id', 'proj-123')
            ->assertJsonPath('provider', 'deepgram');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/projects/proj-123/keys')
            && $request['scopes'] === ['usage:write']);
    }

    public function test_deepgram_uses_a_configured_project_id_without_calling_projects_endpoint(): void
    {
        $this->actingAsFakeUser();

        config(['voice.realtime.providers.deepgram.project_id' => 'configured-project']);

        Http::fake([
            'api.deepgram.com/v1/projects/configured-project/keys' => Http::response([
                'key' => 'dg_scoped_test_key',
            ]),
        ]);

        $this->postJson('/voice/realtime/token', ['provider' => 'deepgram'])
            ->assertOk()
            ->assertJsonPath('project_id', 'configured-project');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/projects')
            && ! str_contains($request->url(), '/keys'));
    }

    public function test_a_deepgram_key_creation_failure_returns_a_generic_502(): void
    {
        $this->actingAsFakeUser();

        Http::fake([
            'api.deepgram.com/v1/projects' => Http::response([
                'projects' => [['project_id' => 'proj-123']],
            ]),
            'api.deepgram.com/v1/projects/proj-123/keys' => Http::response([
                'category' => 'INSUFFICIENT_PERMISSIONS',
                'message' => "Check that your account has the 'keys:write' scope for this project.",
            ], 403),
        ]);

        $response = $this->postJson('/voice/realtime/token', ['provider' => 'deepgram']);

        $response->assertStatus(502);
        $this->assertStringNotContainsString('INSUFFICIENT_PERMISSIONS', $response->getContent());
    }
}
