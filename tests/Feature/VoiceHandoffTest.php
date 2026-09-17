<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Events\HandoffCompleted;
use EasyAI\LaravelVoice\Events\HandoffRequested;
use EasyAI\LaravelVoice\Events\SessionEnded;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class VoiceHandoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Voice::registerAgent('support', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai');
        });
    }

    public function test_requesting_a_handoff_fires_an_event_without_ending_the_session(): void
    {
        Event::fake();

        $agent = Voice::agent('support');
        $session = $agent->startSession(['user_id' => 1]);

        $agent->requestHandoff($session, 'Caller asked for a refund - out of scope for this agent.');

        Event::assertDispatched(HandoffRequested::class, fn ($e) => $e->session->is($session)
            && str_contains($e->reason, 'refund'));

        $session->refresh();
        $this->assertSame('active', $session->status);
    }

    public function test_completing_a_handoff_ends_the_session_and_records_the_reason(): void
    {
        Event::fake();

        $agent = Voice::agent('support');
        $session = $agent->startSession(['user_id' => 1]);

        $agent->completeHandoff($session, 'Transferred to billing.');

        $session->refresh();
        $this->assertSame('ended', $session->status);
        $this->assertNotNull($session->ended_at);
        $this->assertTrue($session->metadata['handoff']);
        $this->assertSame('Transferred to billing.', $session->metadata['handoff_reason']);

        Event::assertDispatched(HandoffCompleted::class);
        Event::assertDispatched(SessionEnded::class);
    }

    public function test_completing_a_handoff_preserves_existing_metadata(): void
    {
        $agent = Voice::agent('support');
        $session = $agent->startSession(['user_id' => 1, 'metadata' => ['source' => 'ivr']]);

        $agent->completeHandoff($session, 'Escalated.');

        $session->refresh();
        $this->assertSame('ivr', $session->metadata['source']);
        $this->assertTrue($session->metadata['handoff']);
    }
}
