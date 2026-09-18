<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Providers\Stt\DeepgramSttProvider;
use EasyAI\LaravelVoice\Support\TranscriptionResult;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;

class DeepgramSttProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.stt.providers.deepgram', [
            'api_key' => 'test-key',
            'url' => 'https://api.deepgram.com/v1',
            'model' => 'nova-2',
            'timeout' => 10,
            'max_file_size' => 25 * 1024 * 1024,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);
    }

    protected function makeTempAudioFile(string $contents = 'fake-audio-bytes'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.wav';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_it_transcribes_audio_via_deepgram(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response([
                'metadata' => ['duration' => 4.2],
                'results' => [
                    'channels' => [[
                        'alternatives' => [[
                            'transcript' => 'hello from deepgram',
                        ]],
                    ]],
                ],
            ]),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            $result = Voice::stt('deepgram')->transcribe($path);

            $this->assertInstanceOf(TranscriptionResult::class, $result);
            $this->assertSame('hello from deepgram', $result->text);
            $this->assertSame(4.2, $result->durationSeconds);

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'api.deepgram.com/v1/listen')
                    && $request->hasHeader('Authorization', 'Token test-key')
                    && $request->body() === 'fake-audio-bytes';
            });
        } finally {
            unlink($path);
        }
    }

    public function test_it_sends_model_and_language_as_query_parameters(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response([
                'results' => ['channels' => [['alternatives' => [['transcript' => 'x']]]]],
            ]),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            Voice::stt('deepgram')->transcribe($path, ['language' => 'bn']);

            Http::assertSent(fn ($request) => str_contains($request->url(), 'model=nova-2')
                && str_contains($request->url(), 'language=bn'));
        } finally {
            unlink($path);
        }
    }

    public function test_it_allows_overriding_the_model_per_call(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response([
                'results' => ['channels' => [['alternatives' => [['transcript' => 'x']]]]],
            ]),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            Voice::stt('deepgram')->transcribe($path, ['model' => 'nova-3']);

            Http::assertSent(fn ($request) => str_contains($request->url(), 'model=nova-3'));
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_on_missing_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Voice::stt('deepgram')->transcribe('/path/does/not/exist.wav');
    }

    public function test_it_rejects_oversized_files(): void
    {
        config(['voice.stt.providers.deepgram.max_file_size' => 10]);

        $path = $this->makeTempAudioFile('this-file-is-longer-than-ten-bytes');

        try {
            $this->expectException(\InvalidArgumentException::class);
            Voice::stt('deepgram')->transcribe($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_empty_files(): void
    {
        $path = $this->makeTempAudioFile('');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('empty');
            Voice::stt('deepgram')->transcribe($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_when_api_key_is_missing(): void
    {
        config(['voice.stt.providers.deepgram.api_key' => null]);

        $path = $this->makeTempAudioFile();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('API key is not configured');
            Voice::stt('deepgram')->transcribe($path);

            Http::assertNothingSent();
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_when_api_key_is_blank(): void
    {
        config(['voice.stt.providers.deepgram.api_key' => '   ']);

        $path = $this->makeTempAudioFile();

        try {
            $this->expectException(\InvalidArgumentException::class);
            Voice::stt('deepgram')->transcribe($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_provider_exception_on_error_response(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response(['err_msg' => 'bad'], 400),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            $this->expectException(ProviderException::class);
            Voice::stt('deepgram')->transcribe($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_provider_exception_on_invalid_api_key(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response(['err_code' => 'INVALID_AUTH', 'err_msg' => 'Invalid credentials.'], 401),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            Voice::stt('deepgram')->transcribe($path);
            $this->fail('Expected a ProviderException to be thrown.');
        } catch (ProviderException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('deepgram', $e->getProvider());
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_it_does_not_leak_the_api_key_in_exception_output(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response(['err_msg' => 'server error'], 500),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            Voice::stt('deepgram')->transcribe($path);
            $this->fail('Expected a ProviderException to be thrown.');
        } catch (ProviderException $e) {
            $this->assertStringNotContainsString('test-key', $e->getMessage());
            $this->assertStringNotContainsString('test-key', json_encode($e->getContext()));
        } finally {
            unlink($path);
        }
    }

    public function test_it_handles_an_empty_transcript_without_throwing(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response([
                'metadata' => ['duration' => 1.0],
                'results' => [
                    'channels' => [[
                        'alternatives' => [[
                            'transcript' => '',
                        ]],
                    ]],
                ],
            ]),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            $result = Voice::stt('deepgram')->transcribe($path);

            $this->assertInstanceOf(TranscriptionResult::class, $result);
            $this->assertSame('', $result->text);
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_provider_exception_on_malformed_json_response(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response('not valid json{{{', 200, ['Content-Type' => 'application/json']),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            $this->expectException(ProviderException::class);
            $this->expectExceptionMessage('malformed');
            Voice::stt('deepgram')->transcribe($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_provider_exception_when_results_field_is_missing(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response(['unexpected' => 'shape']),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            $this->expectException(ProviderException::class);
            $this->expectExceptionMessage('results');
            Voice::stt('deepgram')->transcribe($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_connection_exception_on_timeout(): void
    {
        Http::fake(function () {
            throw new HttpConnectionException('Connection timed out');
        });

        $path = $this->makeTempAudioFile();

        try {
            $this->expectException(ConnectionException::class);
            Voice::stt('deepgram')->transcribe($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_connection_exception_on_network_failure(): void
    {
        Http::fake(function () {
            throw new HttpConnectionException('Could not resolve host');
        });

        $path = $this->makeTempAudioFile();

        try {
            Voice::stt('deepgram')->transcribe($path);
            $this->fail('Expected a ConnectionException to be thrown.');
        } catch (ConnectionException $e) {
            $this->assertSame('deepgram', $e->getProvider());
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_provider_is_resolvable_from_the_manager(): void
    {
        $provider = Voice::stt('deepgram');

        $this->assertInstanceOf(DeepgramSttProvider::class, $provider);
    }

    public function test_provider_is_resolved_as_the_default_driver_when_configured(): void
    {
        config(['voice.stt.default' => 'deepgram']);

        $this->assertInstanceOf(DeepgramSttProvider::class, Voice::stt());
    }

    public function test_configuration_values_are_loaded_from_the_config_file(): void
    {
        config(['voice.stt.providers.deepgram.model' => 'nova-3']);

        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response([
                'results' => ['channels' => [['alternatives' => [['transcript' => 'x']]]]],
            ]),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            Voice::stt('deepgram')->transcribe($path);

            Http::assertSent(fn ($request) => str_contains($request->url(), 'model=nova-3'));
        } finally {
            unlink($path);
        }
    }
}
