<?php

namespace EasyAI\LaravelVoice\Providers\Stt;

use EasyAI\LaravelVoice\Contracts\SpeechToTextProvider;
use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Support\TranscriptionResult;
use Illuminate\Support\Facades\Http;

/**
 * Deepgram's /v1/listen takes the raw audio bytes as the request body
 * (Content-Type set to the audio's own mime type) with options as query
 * parameters - a genuinely different wire shape from OpenAI's multipart
 * upload, confirmed against Deepgram's own docs before writing this
 * rather than assumed to match OpenAI's pattern.
 */
class DeepgramSttProvider implements SpeechToTextProvider
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

        $url = rtrim($this->config['url'] ?? 'https://api.deepgram.com/v1', '/').'/listen';

        $query = array_filter([
            'model' => $options['model'] ?? $this->config['model'] ?? 'nova-2',
            'language' => $options['language'] ?? null,
            'smart_format' => 'true',
            'punctuate' => 'true',
        ], fn ($value) => $value !== null);

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 60))
                ->withHeaders(['Authorization' => 'Token '.($this->config['api_key'] ?? '')])
                ->retry((int) ($this->config['retries'] ?? 2), (int) ($this->config['retry_sleep_ms'] ?? 250))
                ->withBody(file_get_contents($audioFilePath), self::mimeTypeForFile($audioFilePath))
                ->post($url.'?'.http_build_query($query));

            if (! $response->successful()) {
                throw new ProviderException(
                    "deepgram transcribe error: {$response->status()} - {$response->body()}",
                    'deepgram',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $data = $response->json() ?? [];
            $alternative = $data['results']['channels'][0]['alternatives'][0] ?? [];

            return new TranscriptionResult(
                text: (string) ($alternative['transcript'] ?? ''),
                language: $options['language'] ?? null,
                durationSeconds: isset($data['metadata']['duration']) ? (float) $data['metadata']['duration'] : null,
                raw: $data,
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "deepgram transcribe connection failed: {$e->getMessage()}",
                'deepgram',
                ['url' => $url],
                0,
                $e
            );
        }
    }

    private static function mimeTypeForFile(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3', 'mpga' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'webm' => 'audio/webm',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            default => 'application/octet-stream',
        };
    }
}
