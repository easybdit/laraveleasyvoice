<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Support\TranscriptionResult;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class SttProviderTest extends TestCase
{
    protected function makeTempAudioFile(string $contents = 'fake-audio-bytes'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.mp3';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_it_transcribes_audio_via_openai(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'hello world']),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            $result = Voice::stt('openai')->transcribe($path);

            $this->assertInstanceOf(TranscriptionResult::class, $result);
            $this->assertSame('hello world', $result->text);

            Http::assertSent(fn ($request) => str_contains($request->url(), 'audio/transcriptions'));
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_on_missing_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Voice::stt('openai')->transcribe('/path/does/not/exist.mp3');
    }

    public function test_it_rejects_oversized_files(): void
    {
        config(['voice.stt.providers.openai.max_file_size' => 10]);

        $path = $this->makeTempAudioFile('this-file-is-longer-than-ten-bytes');

        try {
            $this->expectException(\InvalidArgumentException::class);
            Voice::stt('openai')->transcribe($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_throws_provider_exception_on_error_response(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['error' => 'bad'], 500),
        ]);

        $path = $this->makeTempAudioFile();

        try {
            $this->expectException(ProviderException::class);
            Voice::stt('openai')->transcribe($path);
        } finally {
            unlink($path);
        }
    }
}
