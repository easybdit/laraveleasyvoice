<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Support\TranscriptionResult;
use EasyAI\LaravelVoice\Tests\TestCase;
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

    public function test_it_throws_on_missing_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Voice::stt('deepgram')->transcribe('/path/does/not/exist.wav');
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
}
