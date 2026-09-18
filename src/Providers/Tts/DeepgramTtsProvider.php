<?php

namespace EasyAI\LaravelVoice\Providers\Tts;

use EasyAI\LaravelVoice\Contracts\TextToSpeechProvider;
use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Support\AudioResult;
use Illuminate\Support\Facades\Http;

/**
 * Deepgram's /v1/speak takes the text as a JSON request body ({"text": "..."})
 * with "model" and "encoding" as query parameters, and returns the raw
 * audio bytes directly in the response body - confirmed against Deepgram's
 * own current API reference before writing this: POST /v1/speak,
 * "Authorization: Token <key>" + "Content-Type: application/json", a
 * documented 2000-character request limit (Aura-1 and Aura-2 alike, a 413
 * past that), and error bodies shaped as either the legacy
 * {err_code, err_msg, request_id} or the newer {category, message,
 * details, request_id}.
 *
 * Deepgram has no separate "voice" parameter the way OpenAI/ElevenLabs do -
 * the "model" value itself selects the voice (e.g. aura-2-thalia-en), so
 * this provider intentionally has no voice option. Only "encoding" is
 * exposed (as this config array's "format" key, matching the other two
 * providers' naming) - "container"/"sample_rate"/"bit_rate"/"speed" are
 * real Deepgram parameters too but aren't wired up here to keep this a
 * buffered, non-streaming increment no larger than what was asked for.
 */
class DeepgramTtsProvider implements TextToSpeechProvider
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

        $maxLength = (int) ($this->config['max_input_length'] ?? 2000);

        if (mb_strlen($text) > $maxLength) {
            throw new \InvalidArgumentException("Text exceeds the maximum allowed length of {$maxLength} characters.");
        }

        if (trim((string) ($this->config['api_key'] ?? '')) === '') {
            throw new \InvalidArgumentException('Deepgram API key is not configured. Set VOICE_DEEPGRAM_API_KEY or voice.tts.providers.deepgram.api_key.');
        }

        $url = rtrim($this->config['url'] ?? 'https://api.deepgram.com/v1', '/').'/speak';
        $format = $options['format'] ?? $this->config['format'] ?? 'mp3';

        $query = array_filter([
            'model' => $options['model'] ?? $this->config['model'] ?? 'aura-2-thalia-en',
            'encoding' => $format,
        ], fn ($value) => $value !== null);

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 60))
                ->withHeaders(['Authorization' => 'Token '.($this->config['api_key'] ?? '')])
                ->retry((int) ($this->config['retries'] ?? 2), (int) ($this->config['retry_sleep_ms'] ?? 250))
                ->post($url.'?'.http_build_query($query), ['text' => $text]);

            if (! $response->successful()) {
                throw new ProviderException(
                    "deepgram speech error: {$response->status()} - {$response->body()}",
                    'deepgram',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $binary = $response->body();

            if ($binary === '') {
                throw new ProviderException(
                    'deepgram speech error: received an empty audio response',
                    'deepgram',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            return new AudioResult(
                binary: $binary,
                mimeType: self::mimeTypeForFormat($format),
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "deepgram speech connection failed: {$e->getMessage()}",
                'deepgram',
                ['url' => $url],
                0,
                $e
            );
        }
    }

    /**
     * Content-Type per "encoding", confirmed against Deepgram's own media
     * output settings docs. mp3 is this provider's default and the only
     * one exercised by the automated test suite; the rest are mapped
     * directly from that same reference table.
     */
    private static function mimeTypeForFormat(string $encoding): string
    {
        return match ($encoding) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            'opus' => 'audio/ogg;codecs=opus',
            'linear16', 'mulaw', 'alaw' => 'audio/wav',
            default => 'application/octet-stream',
        };
    }
}
