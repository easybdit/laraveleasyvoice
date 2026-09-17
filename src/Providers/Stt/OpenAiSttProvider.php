<?php

namespace EasyAI\LaravelVoice\Providers\Stt;

use EasyAI\LaravelVoice\Contracts\SpeechToTextProvider;
use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Support\TranscriptionResult;
use Illuminate\Support\Facades\Http;

class OpenAiSttProvider implements SpeechToTextProvider
{
    public function __construct(protected array $config)
    {
    }

    public function transcribe(string $audioFilePath, array $options = []): TranscriptionResult
    {
        if (! is_file($audioFilePath)) {
            throw new \InvalidArgumentException("Audio file not found: {$audioFilePath}");
        }

        $maxSize = (int) ($this->config['max_file_size'] ?? 25 * 1024 * 1024);
        $size = filesize($audioFilePath);

        if ($size === false || $size > $maxSize) {
            throw new \InvalidArgumentException("Audio file exceeds the maximum allowed size of {$maxSize} bytes.");
        }

        $url = rtrim($this->config['url'] ?? '', '/').'/audio/transcriptions';

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 60))
                ->withToken($this->config['api_key'] ?? '')
                ->retry((int) ($this->config['retries'] ?? 2), (int) ($this->config['retry_sleep_ms'] ?? 250))
                ->attach('file', file_get_contents($audioFilePath), basename($audioFilePath))
                ->post($url, array_filter([
                    'model' => $options['model'] ?? $this->config['model'] ?? 'whisper-1',
                    'language' => $options['language'] ?? null,
                    'prompt' => $options['prompt'] ?? null,
                ], fn ($value) => $value !== null));

            if (! $response->successful()) {
                throw new ProviderException(
                    "openai transcribe error: {$response->status()} - {$response->body()}",
                    'openai',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            return new TranscriptionResult(
                text: (string) ($response->json('text') ?? ''),
                language: $options['language'] ?? null,
                raw: $response->json() ?? [],
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "openai transcribe connection failed: {$e->getMessage()}",
                'openai',
                ['url' => $url],
                0,
                $e
            );
        }
    }
}
