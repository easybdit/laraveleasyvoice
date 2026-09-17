<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Support\AudioResult;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class ElevenLabsTtsProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.tts.providers.elevenlabs', [
            'api_key' => 'test-key',
            'url' => 'https://api.elevenlabs.io/v1',
            'model' => 'eleven_multilingual_v2',
            'voice' => 'voice-123',
            'format' => 'mp3_44100_128',
            'timeout' => 10,
            'max_input_length' => 5000,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);
    }

    public function test_it_synthesizes_speech_via_elevenlabs(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $result = Voice::tts('elevenlabs')->synthesize('Hello there');

        $this->assertInstanceOf(AudioResult::class, $result);
        $this->assertSame('binary-audio-bytes', $result->binary);
        $this->assertSame('audio/mpeg', $result->mimeType);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/text-to-speech/voice-123')
                && str_contains($request->url(), 'output_format=mp3_44100_128')
                && $request->hasHeader('xi-api-key', 'test-key')
                && $request['text'] === 'Hello there';
        });
    }

    public function test_it_requires_a_voice_id(): void
    {
        config(['voice.tts.providers.elevenlabs.voice' => null]);

        $this->expectException(\InvalidArgumentException::class);

        Voice::tts('elevenlabs')->synthesize('Hello there');
    }

    public function test_it_rejects_empty_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Voice::tts('elevenlabs')->synthesize('   ');
    }

    public function test_it_rejects_text_over_max_length(): void
    {
        config(['voice.tts.providers.elevenlabs.max_input_length' => 5]);

        $this->expectException(\InvalidArgumentException::class);

        Voice::tts('elevenlabs')->synthesize('this is too long');
    }

    public function test_it_throws_provider_exception_on_error_response(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response(['detail' => 'bad'], 422),
        ]);

        $this->expectException(ProviderException::class);

        Voice::tts('elevenlabs')->synthesize('Hello there');
    }
}
