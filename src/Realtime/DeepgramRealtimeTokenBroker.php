<?php

namespace EasyAI\LaravelVoice\Realtime;

use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Mints a short-lived, scoped Deepgram API key server-side, so a browser
 * can open a connection to Deepgram's Voice Agent API without the real
 * account key ever reaching it - the same purpose as
 * OpenAiRealtimeTokenBroker, for a different provider.
 *
 * This is deliberately this package's ENTIRE Deepgram realtime footprint
 * today. Unlike OpenAI's Realtime API (WebRTC + SDP), Deepgram's Voice
 * Agent API is a raw WebSocket protocol streaming linear16 PCM audio
 * frames both ways, authenticated via the Sec-WebSocket-Protocol header
 * (subprotocols `token` and the key itself) rather than a normal
 * Authorization header - browsers cannot set arbitrary headers on a
 * WebSocket handshake, only subprotocols, which is why this differs from
 * a plain bearer token. No browser client for that connection exists in
 * this package yet - building it is separate, real work (raw PCM
 * capture/playback via the Web Audio API, not the MediaRecorder-based
 * approach voice-widget.js already uses), tracked separately from this
 * token-minting piece.
 *
 * Every endpoint/field here was checked against Deepgram's current API
 * reference, then corrected against a real, live call the same way
 * OpenAiRealtimeTokenBroker's was - documentation review alone was wrong
 * about one field: `scopes` is documented as optional but a live 400
 * (`INVALID_JSON: missing field \`scopes\``) proved it's actually
 * required. Fixed to send `['usage:write']` by default - the minimal
 * scope needed to make transcription/agent calls, deliberately not
 * `keys:write` (would let this browser-facing token create/delete other
 * keys) or the broader `member` role.
 *
 * One more thing confirmed live, not just documented: creating a scoped
 * key requires the *calling* key (the real account key configured
 * server-side) to itself have `keys:write` permission - a default/member
 * key does not have this by default and gets a live `403
 * INSUFFICIENT_PERMISSIONS`. The server-side key needs an Owner/Admin
 * role (or explicit `keys:write` scope) in Deepgram's own console; the
 * resulting minted token stays minimally scoped to `usage:write`
 * regardless. A full successful mint has been confirmed live against a
 * real Deepgram account with such a key.
 */
class DeepgramRealtimeTokenBroker
{
    public function __construct(protected array $config)
    {
    }

    /**
     * @return array{token: string, expires_at: int, project_id: string}
     */
    public function createEphemeralToken(array $options = []): array
    {
        $baseUrl = rtrim($this->config['url'] ?? 'https://api.deepgram.com/v1', '/');
        $apiKey = $this->config['api_key'] ?? '';

        $projectId = $this->config['project_id'] ?? $this->resolveProjectId($baseUrl, $apiKey);

        $ttlSeconds = min((int) ($options['ttl_seconds'] ?? $this->config['ttl_seconds'] ?? 3600), 3600);
        $expiresAt = Carbon::now()->addSeconds($ttlSeconds);

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 15))
                ->withHeaders(['Authorization' => 'Token '.$apiKey])
                ->post("{$baseUrl}/projects/{$projectId}/keys", [
                    'comment' => 'laraveleasyvoice ephemeral realtime key',
                    'expiration_date' => $expiresAt->toIso8601String(),
                    // scopes is undocumented-as-required but rejected as
                    // missing by the real API (confirmed live) - 'usage:write'
                    // is the minimal scope needed to actually make
                    // transcription/agent calls; deliberately NOT
                    // 'keys:write' (would let this browser-facing token
                    // create/delete other keys) or 'member' (broader than
                    // this token needs).
                    'scopes' => $this->config['scopes'] ?? ['usage:write'],
                ]);

            if (! $response->successful()) {
                throw new ProviderException(
                    "deepgram realtime token error: {$response->status()} - {$response->body()}",
                    'deepgram',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $data = $response->json() ?? [];

            return [
                'token' => (string) ($data['key'] ?? ''),
                'expires_at' => $expiresAt->timestamp,
                'project_id' => $projectId,
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "deepgram realtime token connection failed: {$e->getMessage()}",
                'deepgram',
                ['url' => $baseUrl],
                0,
                $e
            );
        }
    }

    private function resolveProjectId(string $baseUrl, string $apiKey): string
    {
        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 15))
                ->withHeaders(['Authorization' => 'Token '.$apiKey])
                ->get("{$baseUrl}/projects");

            if (! $response->successful()) {
                throw new ProviderException(
                    "deepgram project lookup error: {$response->status()} - {$response->body()}",
                    'deepgram',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $projects = $response->json('projects') ?? [];

            if (empty($projects)) {
                throw new ProviderException(
                    'deepgram project lookup returned no projects for this API key',
                    'deepgram'
                );
            }

            return (string) $projects[0]['project_id'];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "deepgram project lookup connection failed: {$e->getMessage()}",
                'deepgram',
                ['url' => $baseUrl],
                0,
                $e
            );
        }
    }
}
