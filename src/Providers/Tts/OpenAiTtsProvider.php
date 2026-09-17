<?php

namespace EasyAI\LaravelVoice\Providers\Tts;

use EasyAI\LaravelVoice\Contracts\TextToSpeechProvider;
use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Support\AudioResult;
use Illuminate\Support\Facades\Http;

class OpenAiTtsProvider implements TextToSpeechProvider
{
    public function __construct(protected array $config)
    {
    }

    public function synthesize(string $text, array $options = []): AudioResult
    {
        $text = trim($text);

        if ($text === '') {
            throw new \InvalidArgumentException('Cannot synthesize empty text.');
        }

        $maxLength = (int) ($this->config['max_input_length'] ?? 4096);

        if (mb_strlen($text) > $maxLength) {
            throw new \InvalidArgumentException("Text exceeds the maximum allowed length of {$maxLength} characters.");
        }

        $url = rtrim($this->config['url'] ?? '', '/').'/audio/speech';
        $format = $options['format'] ?? $this->config['format'] ?? 'mp3';

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 60))
                ->withToken($this->config['api_key'] ?? '')
                ->retry((int) ($this->config['retries'] ?? 2), (int) ($this->config['retry_sleep_ms'] ?? 250))
                ->post($url, [
                    'model' => $options['model'] ?? $this->config['model'] ?? 'tts-1',
                    'input' => $text,
                    'voice' => $options['voice'] ?? $this->config['voice'] ?? 'alloy',
                    'response_format' => $format,
                ]);

            if (! $response->successful()) {
                throw new ProviderException(
                    "openai speech error: {$response->status()} - {$response->body()}",
                    'openai',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            return new AudioResult(
                binary: $response->body(),
                mimeType: self::mimeTypeForFormat($format),
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "openai speech connection failed: {$e->getMessage()}",
                'openai',
                ['url' => $url],
                0,
                $e
            );
        }
    }

    protected static function mimeTypeForFormat(string $format): string
    {
        return match ($format) {
            'mp3' => 'audio/mpeg',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'pcm' => 'audio/pcm',
            default => 'application/octet-stream',
        };
    }
}
