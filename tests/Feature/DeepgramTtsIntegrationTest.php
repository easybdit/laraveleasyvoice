<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Support\AudioResult;
use EasyAI\LaravelVoice\Tests\TestCase;

/**
 * Opt-in, real-network test against the actual Deepgram TTS API. Skipped
 * entirely unless a real key is present in the environment - never runs
 * as part of the normal `vendor/bin/phpunit` suite, never requires a key
 * in CI, and never puts a real credential in the repository.
 *
 * Run it locally with:
 *   DEEPGRAM_API_KEY=your-real-key vendor/bin/phpunit --filter DeepgramTtsIntegrationTest
 */
class DeepgramTtsIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $apiKey = env('DEEPGRAM_API_KEY') ?: env('VOICE_DEEPGRAM_API_KEY') ?: null;

        if (! $apiKey) {
            $this->markTestSkipped('Set DEEPGRAM_API_KEY (or VOICE_DEEPGRAM_API_KEY) to run the live Deepgram TTS integration test.');
        }

        config(['voice.tts.providers.deepgram.api_key' => $apiKey]);
    }

    public function test_it_synthesizes_real_speech_via_the_live_deepgram_api(): void
    {
        $result = Voice::tts('deepgram')->synthesize('Hello from LaravelEasyVoice.');

        $this->assertInstanceOf(AudioResult::class, $result);
        $this->assertNotSame('', $result->binary);
        $this->assertGreaterThan(0, strlen($result->binary));
        $this->assertSame('audio/mpeg', $result->mimeType);
    }
}
