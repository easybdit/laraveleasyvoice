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
 * realtime connection is not implemented.
 *
 * The request body shape below was corrected twice against a real, live
 * call to OpenAI's API on a real account, not just docs review - the
 * initial version (built from documentation alone) was missing the
 * required `session.type: "realtime"` field, and after adding that,
 * `session.voice` turned out to have moved to the nested
 * `session.audio.output.voice` in OpenAI's current (GA) schema. Both
 * were caught immediately as clear 400s from the real API, confirming
 * "verified against docs" and "verified against a live call" are not
 * the same claim - this file is now the latter.
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

        $model = $options['model'] ?? $this->config['model'] ?? 'gpt-realtime';
        $voice = $options['voice'] ?? $this->config['voice'] ?? 'alloy';

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 15))
                ->withToken($this->config['api_key'] ?? '')
                ->post($url, [
                    'session' => [
                        'type' => 'realtime',
                        'model' => $model,
                        'audio' => [
                            'output' => [
                                'voice' => $voice,
                            ],
                        ],
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
