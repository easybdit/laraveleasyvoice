<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Tests\Support\FakeUser;
use EasyAI\LaravelVoice\Tests\TestCase;

class VoiceHttpTenantTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.routes.enabled', true);
        $app['config']->set('voice.routes.middleware', ['web', 'auth']);

        // A simple header-based resolver - a real host app would resolve
        // this from its own tenancy package/domain/session instead.
        $app['config']->set('voice.routes.tenant_resolver', function ($request) {
            return $request->header('X-Tenant-Id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Voice::registerAgent('receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai');
        });
    }

    protected function actingAsFakeUser(int $id): FakeUser
    {
        $user = new FakeUser($id);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_session_is_created_with_the_resolved_tenant_id(): void
    {
        $this->actingAsFakeUser(1);

        $sessionId = $this->withHeaders(['X-Tenant-Id' => '42'])
            ->postJson('/voice/sessions', ['agent' => 'receptionist'])
            ->json('id');

        $this->assertDatabaseHas('voice_sessions', [
            'id' => $sessionId,
            'tenant_id' => 42,
        ]);
    }

    public function test_the_same_user_id_under_a_different_tenant_cannot_access_the_session(): void
    {
        // Same authenticated user id in both requests - only the tenant
        // header differs - proves isOwnedBy()'s tenant check, not just its
        // user_id check, is what's blocking access here.
        $this->actingAsFakeUser(1);

        $sessionId = $this->withHeaders(['X-Tenant-Id' => '1'])
            ->postJson('/voice/sessions', ['agent' => 'receptionist'])
            ->json('id');

        $this->actingAsFakeUser(1);

        $this->withHeaders(['X-Tenant-Id' => '2'])
            ->postJson("/voice/sessions/{$sessionId}/end", [])
            ->assertStatus(403);
    }

    public function test_the_same_tenant_and_user_can_access_the_session(): void
    {
        $this->actingAsFakeUser(1);

        $sessionId = $this->withHeaders(['X-Tenant-Id' => '7'])
            ->postJson('/voice/sessions', ['agent' => 'receptionist'])
            ->json('id');

        $this->withHeaders(['X-Tenant-Id' => '7'])
            ->postJson("/voice/sessions/{$sessionId}/end", [])
            ->assertOk()
            ->assertJsonPath('status', 'ended');
    }
}
