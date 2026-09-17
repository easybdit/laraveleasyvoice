<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Support\AudioResult;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class TtsProviderTest extends TestCase
{
    public function test_it_synthesizes_speech_via_openai(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $result = Voice::tts('openai')->synthesize('Hello there');

        $this->assertInstanceOf(AudioResult::class, $result);
        $this->assertSame('binary-audio-bytes', $result->binary);
        $this->assertSame('audio/mpeg', $result->mimeType);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'audio/speech'));
    }

    public function test_it_rejects_empty_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Voice::tts('openai')->synthesize('   ');
    }

    public function test_it_rejects_text_over_max_length(): void
    {
        config(['voice.tts.providers.openai.max_input_length' => 5]);

        $this->expectException(\InvalidArgumentException::class);

        Voice::tts('openai')->synthesize('this is too long');
    }

    public function test_it_throws_provider_exception_on_error_response(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response(['error' => 'bad'], 500),
        ]);

        $this->expectException(ProviderException::class);

        Voice::tts('openai')->synthesize('Hello there');
    }
}
