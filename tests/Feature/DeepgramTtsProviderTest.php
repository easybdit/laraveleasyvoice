<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Providers\Tts\DeepgramTtsProvider;
use EasyAI\LaravelVoice\Support\AudioResult;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;

class DeepgramTtsProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.tts.providers.deepgram', [
            'api_key' => 'test-key',
            'url' => 'https://api.deepgram.com/v1',
            'model' => 'aura-2-thalia-en',
            'format' => 'mp3',
            'timeout' => 10,
            'max_input_length' => 2000,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);
    }

    public function test_it_synthesizes_speech_via_deepgram(): void
    {
        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $result = Voice::tts('deepgram')->synthesize('Hello there');

        $this->assertInstanceOf(AudioResult::class, $result);
        $this->assertSame('binary-audio-bytes', $result->binary);
        $this->assertSame('audio/mpeg', $result->mimeType);
        $this->assertNull($result->durationSeconds);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.deepgram.com/v1/speak')
                && str_contains($request->url(), 'model=aura-2-thalia-en')
                && str_contains($request->url(), 'encoding=mp3')
                && $request->hasHeader('Authorization', 'Token test-key')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request['text'] === 'Hello there';
        });
    }

    public function test_provider_is_resolvable_from_the_manager(): void
    {
        $provider = Voice::tts('deepgram');

        $this->assertInstanceOf(DeepgramTtsProvider::class, $provider);
    }

    public function test_provider_is_resolved_as_the_default_driver_when_configured(): void
    {
        config(['voice.tts.default' => 'deepgram']);

        $this->assertInstanceOf(DeepgramTtsProvider::class, Voice::tts());
    }

    public function test_configuration_values_are_loaded_from_the_config_file(): void
    {
        config(['voice.tts.providers.deepgram.model' => 'aura-2-apollo-en']);

        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        Voice::tts('deepgram')->synthesize('Hello there');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'model=aura-2-apollo-en'));
    }

    public function test_it_allows_overriding_the_model_per_call(): void
    {
        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        Voice::tts('deepgram')->synthesize('Hello there', ['model' => 'aura-2-apollo-en']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'model=aura-2-apollo-en'));
    }

    public function test_it_allows_overriding_the_format_per_call(): void
    {
        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/flac',
            ]),
        ]);

        $result = Voice::tts('deepgram')->synthesize('Hello there', ['format' => 'flac']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'encoding=flac'));
        $this->assertSame('audio/flac', $result->mimeType);
    }

    public function test_mime_type_is_derived_from_the_configured_format(): void
    {
        config(['voice.tts.providers.deepgram.format' => 'opus']);

        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes'),
        ]);

        $result = Voice::tts('deepgram')->synthesize('Hello there');

        $this->assertSame('audio/ogg;codecs=opus', $result->mimeType);
    }

    public function test_it_rejects_empty_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Voice::tts('deepgram')->synthesize('   ');

        Http::assertNothingSent();
    }

    public function test_it_rejects_text_over_max_length(): void
    {
        config(['voice.tts.providers.deepgram.max_input_length' => 5]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the maximum allowed length');

        Voice::tts('deepgram')->synthesize('this is too long');
    }

    public function test_it_throws_when_api_key_is_missing(): void
    {
        config(['voice.tts.providers.deepgram.api_key' => null]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('API key is not configured');
            Voice::tts('deepgram')->synthesize('Hello there');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_throws_when_api_key_is_blank(): void
    {
        config(['voice.tts.providers.deepgram.api_key' => '   ']);

        $this->expectException(\InvalidArgumentException::class);

        Voice::tts('deepgram')->synthesize('Hello there');
    }

    public function test_it_throws_provider_exception_on_error_response(): void
    {
        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response(['err_msg' => 'bad request'], 400),
        ]);

        $this->expectException(ProviderException::class);

        Voice::tts('deepgram')->synthesize('Hello there');
    }

    public function test_it_throws_provider_exception_on_invalid_api_key(): void
    {
        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response(['err_code' => 'INVALID_AUTH', 'err_msg' => 'Invalid credentials.'], 401),
        ]);

        try {
            Voice::tts('deepgram')->synthesize('Hello there');
            $this->fail('Expected a ProviderException to be thrown.');
        } catch (ProviderException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('deepgram', $e->getProvider());
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    public function test_it_throws_provider_exception_on_server_error(): void
    {
        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response(['category' => 'internal', 'message' => 'server error'], 500),
        ]);

        $this->expectException(ProviderException::class);

        Voice::tts('deepgram')->synthesize('Hello there');
    }

    public function test_it_throws_provider_exception_on_an_empty_audio_response(): void
    {
        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response('', 200),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('empty audio response');

        Voice::tts('deepgram')->synthesize('Hello there');
    }

    public function test_it_throws_connection_exception_on_timeout(): void
    {
        Http::fake(function () {
            throw new HttpConnectionException('Connection timed out');
        });

        $this->expectException(ConnectionException::class);

        Voice::tts('deepgram')->synthesize('Hello there');
    }

    public function test_it_throws_connection_exception_on_network_failure(): void
    {
        Http::fake(function () {
            throw new HttpConnectionException('Could not resolve host');
        });

        try {
            Voice::tts('deepgram')->synthesize('Hello there');
            $this->fail('Expected a ConnectionException to be thrown.');
        } catch (ConnectionException $e) {
            $this->assertSame('deepgram', $e->getProvider());
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    public function test_it_does_not_leak_the_api_key_in_exception_output(): void
    {
        Http::fake([
            'api.deepgram.com/v1/speak*' => Http::response(['err_msg' => 'server error'], 500),
        ]);

        try {
            Voice::tts('deepgram')->synthesize('Hello there');
            $this->fail('Expected a ProviderException to be thrown.');
        } catch (ProviderException $e) {
            $this->assertStringNotContainsString('test-key', $e->getMessage());
            $this->assertStringNotContainsString('test-key', json_encode($e->getContext()));
        }
    }
}
