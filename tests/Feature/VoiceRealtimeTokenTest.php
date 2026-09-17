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
        $app['config']->set('voice.realtime.openai', [
            'api_key' => 'test-key',
            'url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-realtime-preview',
            'voice' => 'alloy',
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
                'session' => ['model' => 'gpt-4o-realtime-preview'],
            ]),
        ]);

        $response = $this->postJson('/voice/realtime/token');

        $response->assertOk()
            ->assertJsonPath('token', 'ek_test_12345')
            ->assertJsonPath('expires_at', 1234567890)
            ->assertJsonPath('model', 'gpt-4o-realtime-preview')
            ->assertJsonPath('voice', 'alloy');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'realtime/client_secrets')
                && $request['session']['model'] === 'gpt-4o-realtime-preview'
                && $request['session']['voice'] === 'alloy';
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

        Http::assertSent(fn ($request) => $request['session']['voice'] === 'verse');
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
}
