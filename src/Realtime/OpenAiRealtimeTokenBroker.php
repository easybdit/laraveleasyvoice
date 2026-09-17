<?php

namespace EasyAI\LaravelVoice\Realtime;

use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use Illuminate\Support\Facades\Http;

/**
 * Mints a short-lived OpenAI Realtime API client token server-side, so a
 * browser can open a realtime connection directly to OpenAI without the
 * real API key ever reaching it. This is this package's entire realtime
 * footprint today - see config/voice.php's "realtime" section and
 * Contracts\RealtimeVoiceProvider's docblock for why a full PHP-mediated
 * realtime connection is not implemented. Verified against OpenAI's own
 * current API reference before writing this (POST /v1/realtime/client_secrets,
 * request body `{"session": {"model", "voice"}}`, response field
 * `value` - an `ek_`-prefixed token - plus `expires_at`), not guessed.
 */
class OpenAiRealtimeTokenBroker
{
    public function __construct(protected array $config)
    {
    }

    /**
     * @return array{token: string, expires_at: int|null, model: string, voice: string}
     */
    public function createEphemeralToken(array $options = []): array
    {
        $url = rtrim($this->config['url'] ?? 'https://api.openai.com/v1', '/').'/realtime/client_secrets';

        $model = $options['model'] ?? $this->config['model'] ?? 'gpt-4o-realtime-preview';
        $voice = $options['voice'] ?? $this->config['voice'] ?? 'alloy';

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 15))
                ->withToken($this->config['api_key'] ?? '')
                ->post($url, [
                    'session' => [
                        'model' => $model,
                        'voice' => $voice,
                    ],
                ]);

            if (! $response->successful()) {
                throw new ProviderException(
                    "openai realtime token error: {$response->status()} - {$response->body()}",
                    'openai',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $data = $response->json() ?? [];

            return [
                'token' => (string) ($data['value'] ?? ''),
                'expires_at' => isset($data['expires_at']) ? (int) $data['expires_at'] : null,
                'model' => $model,
                'voice' => $voice,
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "openai realtime token connection failed: {$e->getMessage()}",
                'openai',
                ['url' => $url],
                0,
                $e
            );
        }
    }
}
