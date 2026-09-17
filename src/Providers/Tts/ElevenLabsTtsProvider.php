<?php

namespace EasyAI\LaravelVoice\Providers\Tts;

use EasyAI\LaravelVoice\Contracts\TextToSpeechProvider;
use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Support\AudioResult;
use Illuminate\Support\Facades\Http;

class ElevenLabsTtsProvider implements TextToSpeechProvider
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

        $maxLength = (int) ($this->config['max_input_length'] ?? 5000);

        if (mb_strlen($text) > $maxLength) {
            throw new \InvalidArgumentException("Text exceeds the maximum allowed length of {$maxLength} characters.");
        }

        $voiceId = $options['voice'] ?? $this->config['voice'] ?? null;

        if (! $voiceId) {
            throw new \InvalidArgumentException('ElevenLabs requires a voice id - set voice.tts.providers.elevenlabs.voice or pass the "voice" option.');
        }

        $url = rtrim($this->config['url'] ?? 'https://api.elevenlabs.io/v1', '/')."/text-to-speech/{$voiceId}";
        $format = $options['format'] ?? $this->config['format'] ?? 'mp3_44100_128';

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 60))
                ->withHeaders(['xi-api-key' => $this->config['api_key'] ?? ''])
                ->retry((int) ($this->config['retries'] ?? 2), (int) ($this->config['retry_sleep_ms'] ?? 250))
                ->post($url.'?'.http_build_query(['output_format' => $format]), array_filter([
                    'text' => $text,
                    'model_id' => $options['model'] ?? $this->config['model'] ?? 'eleven_multilingual_v2',
                ]));

            if (! $response->successful()) {
                throw new ProviderException(
                    "elevenlabs speech error: {$response->status()} - {$response->body()}",
                    'elevenlabs',
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
                "elevenlabs speech connection failed: {$e->getMessage()}",
                'elevenlabs',
                ['url' => $url],
                0,
                $e
            );
        }
    }

    private static function mimeTypeForFormat(string $format): string
    {
        return match (true) {
            str_starts_with($format, 'mp3') => 'audio/mpeg',
            str_starts_with($format, 'opus') => 'audio/opus',
            str_starts_with($format, 'wav') => 'audio/wav',
            str_starts_with($format, 'pcm') => 'audio/pcm',
            str_starts_with($format, 'ulaw') || str_starts_with($format, 'alaw') => 'audio/basic',
            default => 'application/octet-stream',
        };
    }
}
