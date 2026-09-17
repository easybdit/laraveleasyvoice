<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Support\VoiceIdentity;
use EasyAI\LaravelVoice\Tests\TestCase;

class VoiceHttpGuestTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A separate opt-in configuration, deliberately not the default -
        // guest access must be turned on explicitly in both places
        // ('auth' removed from middleware AND allow_guest = true).
        $app['config']->set('voice.routes.enabled', true);
        $app['config']->set('voice.routes.middleware', ['web']);
        $app['config']->set('voice.routes.allow_guest', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Voice::registerAgent('receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai');
        });
    }

    public function test_a_first_time_guest_gets_a_session_and_a_cookie(): void
    {
        $response = $this->postJson('/voice/sessions', ['agent' => 'receptionist']);

        $response->assertStatus(201);
        $response->assertCookie(VoiceIdentity::COOKIE_NAME);

        $this->assertDatabaseHas('voice_sessions', [
            'id' => $response->json('id'),
            'user_id' => null,
        ]);
    }

    public function test_a_returning_guest_owns_their_earlier_session(): void
    {
        $token = str_repeat('a', 40);

        // postJson() only sends cookies at all when withCredentials() is
        // also set (Illuminate\Foundation\Testing\Concerns\MakesHttpRequests
        // ::prepareCookiesForJsonRequest()) - without it these cookies are
        // silently dropped and the server sees an anonymous guest instead.
        $sessionId = $this->withCredentials()->withCookies([VoiceIdentity::COOKIE_NAME => $token])
            ->postJson('/voice/sessions', ['agent' => 'receptionist'])
            ->json('id');

        $this->withCredentials()->withCookies([VoiceIdentity::COOKIE_NAME => $token])
            ->postJson("/voice/sessions/{$sessionId}/end", [])
            ->assertOk()
            ->assertJsonPath('status', 'ended');
    }

    public function test_a_different_guest_cookie_cannot_access_someone_elses_session(): void
    {
        $token1 = str_repeat('a', 40);
        $token2 = str_repeat('b', 40);

        $sessionId = $this->withCredentials()->withCookies([VoiceIdentity::COOKIE_NAME => $token1])
            ->postJson('/voice/sessions', ['agent' => 'receptionist'])
            ->json('id');

        $this->withCredentials()->withCookies([VoiceIdentity::COOKIE_NAME => $token2])
            ->postJson("/voice/sessions/{$sessionId}/end", [])
            ->assertStatus(403);
    }
}
