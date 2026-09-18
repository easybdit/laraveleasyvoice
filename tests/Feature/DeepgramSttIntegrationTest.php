<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Support\TranscriptionResult;
use EasyAI\LaravelVoice\Tests\TestCase;

/**
 * Opt-in, real-network test against the actual Deepgram API. Skipped
 * entirely unless a real key is present in the environment - never runs
 * as part of the normal `vendor/bin/phpunit` suite, never requires a key
 * in CI, and never puts a real credential in the repository.
 *
 * Run it locally with:
 *   DEEPGRAM_API_KEY=your-real-key vendor/bin/phpunit --filter DeepgramSttIntegrationTest
 */
class DeepgramSttIntegrationTest extends TestCase
{
    private ?string $apiKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = env('DEEPGRAM_API_KEY') ?: env('VOICE_DEEPGRAM_API_KEY') ?: null;

        if (! $this->apiKey) {
            $this->markTestSkipped('Set DEEPGRAM_API_KEY to run the live Deepgram integration test.');
        }

        config(['voice.stt.providers.deepgram.api_key' => $this->apiKey]);
    }

    public function test_it_transcribes_a_real_audio_file_via_the_live_deepgram_api(): void
    {
        $path = __DIR__.'/../Fixtures/sample-tone.wav';

        $this->assertFileExists($path);

        $result = Voice::stt('deepgram')->transcribe($path);

        $this->assertInstanceOf(TranscriptionResult::class, $result);
        $this->assertIsString($result->text);
        $this->assertArrayHasKey('results', $result->raw);
        $this->assertArrayHasKey('metadata', $result->raw);
    }
}
