<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Analytics\VoiceUsage;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VoiceUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Voice::registerAgent('receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai');
        });
    }

    protected function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.mp3';
        file_put_contents($path, 'fake-audio-bytes');

        return $path;
    }

    protected function runOneTurn(int $tenantId): void
    {
        config(['ai.pricing.openai.gpt-4o-mini' => ['input' => 0.01, 'output' => 0.03]]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello', 'duration' => 1.0]),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hi there.']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1, 'tenant_id' => $tenantId]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }
    }

    public function test_summary_aggregates_across_all_sessions(): void
    {
        $this->runOneTurn(tenantId: 1);
        $this->runOneTurn(tenantId: 2);

        $summary = VoiceUsage::summary();

        $this->assertSame(2, $summary['sessions']);
        $this->assertSame(2, $summary['ended_sessions'] + $summary['active_sessions']);
        $this->assertSame(2000, $summary['total_stt_ms']);
        $this->assertSame(200, $summary['total_prompt_tokens']);
        $this->assertSame(100, $summary['total_completion_tokens']);
        $this->assertEqualsWithDelta(0.005, $summary['estimated_cost'], 0.0001);
        $this->assertSame(4, $summary['turns']); // 2 sessions x (1 user turn + 1 assistant turn)
    }

    public function test_summary_can_be_scoped_by_filters(): void
    {
        $this->runOneTurn(tenantId: 1);
        $this->runOneTurn(tenantId: 2);

        $summary = VoiceUsage::summary(filters: ['tenant_id' => 1]);

        $this->assertSame(1, $summary['sessions']);
        $this->assertSame(1000, $summary['total_stt_ms']);
    }

    public function test_for_session_reports_per_session_usage(): void
    {
        $this->runOneTurn(tenantId: 1);

        $session = \EasyAI\LaravelVoice\Models\VoiceSession::first();

        $usage = VoiceUsage::forSession($session);

        $this->assertSame($session->id, $usage['session_id']);
        $this->assertSame(2, $usage['turns']);
        $this->assertSame(1, $usage['user_turns']);
        $this->assertSame(1, $usage['assistant_turns']);
        $this->assertSame(1000, $usage['total_stt_ms']);
        $this->assertEqualsWithDelta(0.0025, $usage['estimated_cost'], 0.0001);
        $this->assertNotNull($usage['duration_seconds']);
    }
}
