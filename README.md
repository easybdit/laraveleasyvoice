<h1 align="center">LaravelEasyVoice</h1>

<p align="center">
  <strong>AI voice agents for Laravel, built on top of LaravelEasyAI.</strong><br>
  Speech-to-text, text-to-speech, voice sessions, and voice-enabled tool-calling agents.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/status-pre--release%20(v0.1%20foundation)-orange?style=flat-square" alt="Status">
  <img src="https://img.shields.io/badge/license-MIT-blue?style=flat-square" alt="License">
  <img src="https://img.shields.io/badge/php-%5E8.1-777bb4?style=flat-square" alt="PHP Version">
</p>

---

> **Status: pre-release.** Not yet tagged or published to Packagist. This README documents what's actually implemented today — see [CHANGELOG.md](CHANGELOG.md) for the phase-by-phase build history and the reasoning behind each design decision.

## Why LaravelEasyVoice?

[easybdit/laraveleasyai](https://github.com/easybdit/laraveleasyai) already gives Laravel a unified LLM interface, tool-calling, RAG, and conversation memory. It has no speech capability worth building on — its STT/TTS is a single blocking, OpenAI-only HTTP call, outside its own stable provider contract, with no session model, no events, and no authorization layer for tool calls. LaravelEasyVoice adds the voice-specific layer on top of it — STT/TTS provider abstraction, voice sessions and turns, an event system, and mandatory tool authorization — without reimplementing anything LaravelEasyAI already does well.

```php
// Register an agent once (e.g. in a service provider's boot()) — never in
// config/voice.php, since tool handlers are closures.
Voice::registerAgent('receptionist', function ($agent) {
    $agent->stt('openai')->tts('openai')->llm('openai')
        ->systemPrompt('You are a friendly front-desk receptionist.')
        ->tools([
            AuthorizedTool::make(
                name: 'check_appointment',
                description: "Look up today's appointments",
                parameters: ['type' => 'object', 'properties' => []],
                ability: 'view-appointments',
                handler: fn (array $args) => Appointment::today()->get(),
            ),
        ])
        ->limits(maxTurns: 50, maxSessionSeconds: 1800);
});

// Then, per caller:
$agent = Voice::agent('receptionist');
$session = $agent->startSession(['user_id' => auth()->id()]);

$assistantTurn = $agent->handleTurn($session, $uploadedAudioPath);
// $assistantTurn->transcript  -> the AI's text reply
// $assistantTurn->audio_path  -> synthesized speech, on your private disk
```

## Requirements

- PHP ^8.1
- Laravel 9–13 (`illuminate/support`, `illuminate/http`)
- [`easybdit/laraveleasyai`](https://github.com/easybdit/laraveleasyai) — required, not optional. This package has no LLM logic of its own.

## Installation

```bash
composer require easybdit/laraveleasyvoice
php artisan vendor:publish --tag=voice-config
php artisan migrate
```

`easybdit/laraveleasyai` is a required dependency and is pulled in automatically from Packagist.

<details>
<summary>Developing this package alongside a local LaravelEasyAI checkout</summary>

Add a path repository to your own (uncommitted) local composer config rather than to this package's `composer.json`, so a real install for anyone else is never affected:

```json
"repositories": [
    { "type": "path", "url": "../path/to/laraveleasyai", "options": { "symlink": true } }
]
```
</details>

## What's implemented today

- **`Voice::stt($driver)` / `Voice::tts($driver)`** — provider-agnostic speech contracts (`SpeechToTextProvider`, `TextToSpeechProvider`), with an OpenAI implementation of each.
- **`Voice::registerAgent()` / `Voice::agent()`** — named, reusable voice agents that wire STT → LaravelEasyAI's `AI::provider()->tools()->run()` agent loop → TTS into one call.
- **Sessions & turns** (`voice_sessions`, `voice_turns`) — persisted history per caller, with token/latency accounting on the session.
- **Events** — `SessionStarted`, `SessionEnded`, `SpeechTranscribed`, `ToolCallStarted`, `ToolCallCompleted`, `ResponseSynthesized`, `VoiceError`, all fired through Laravel's own `Event` facade.
- **`AuthorizedTool`** — a tool wrapper that structurally requires a `Gate` ability check before its handler ever runs, failing closed on denial or an undecidable guest check.
- **HTTP routes (opt-in)** — `POST /voice/sessions`, `POST /voice/sessions/{id}/end`, `POST /voice/sessions/{id}/turns` (audio in, transcript + audio URL out), `GET /voice/sessions/{id}/turns/{turn}/audio`. See below.

## HTTP API (opt-in, off by default)

```env
VOICE_ROUTES_ENABLED=true
```

With that set, the routes above run under `['web', 'auth']` by default — every request triggers billed STT/LLM/TTS calls, so there is no guest-open default. A request:

```
POST /voice/sessions          {"agent": "receptionist"}          -> {"id": 1, "agent": "receptionist", "status": "active"}
POST /voice/sessions/1/turns  multipart: audio=<file>             -> {"id": 5, "transcript": "...", "audio_url": "...", "tool_calls": [...]}
GET  /voice/sessions/1/turns/5/audio                              -> streamed audio, ownership-checked
POST /voice/sessions/1/end    {}                                  -> {"id": 1, "status": "ended"}
```

Every session-scoped route 403s for anyone who isn't the session's owner (`VoiceSession::isOwnedBy()`). To allow anonymous callers, set both `voice.routes.allow_guest = true` **and** remove `auth` from `voice.routes.middleware` — a long-lived signed cookie (separate from LaravelEasyAI's own guest cookie) identifies a returning guest, same pattern LaravelEasyAI uses for its chat widget, deliberately kept as an independent config surface so the two packages' access policies can never silently affect each other.

## Not implemented yet

No realtime/WebSocket transport, no barge-in/interruption, no telephony, no non-OpenAI STT/TTS providers. These are sequenced deliberately, not overlooked — each is a larger, harder-to-reverse decision (long-lived connections, provider lock-in, public phone numbers) than anything shipped so far, and gets designed and reviewed on its own. See [CHANGELOG.md](CHANGELOG.md) for the reasoning.

## Security & Trust

This package assumes it will run in front of real, paid, third-party APIs and handle personal (often biometric) voice data, in applications it doesn't control the tenancy model of. Concretely, as of this phase:

- **Fail-closed authorization.** `AuthorizedTool` never invokes its handler without a passing `Gate::allows()` check; an undecidable check (e.g. a guest caller against an ability closure that doesn't declare a nullable `$user` parameter) is denied, not allowed.
- **Fail-closed ownership.** `VoiceSession::isOwnedBy()` denies access to any session without a verifiable matching identity — there's no "no identity recorded, so allow" fallback.
- **Cost and abuse limits are on by default.** `VoiceAgent::limits()` bounds both turns-per-session and session duration, and actually ends the session once exceeded — a voice turn triggers billed STT, LLM, and TTS calls, so unbounded usage was never treated as a safe default.
- **No SSRF surface.** STT only ever reads a local file path you already control; there is no remote-URL ingestion anywhere in this package.
- **Size limits before network calls.** Both STT (file size) and TTS (text length) reject oversized input before making any outbound request.
- **Private storage by default.** Synthesized audio is written to `voice.storage.disk` (default `local`), never a public disk.
- **No invented tenancy.** `tenant_id`/`user_id`/`guest_token` are nullable, FK-less columns — the same posture LaravelEasyAI itself takes — because this package cannot assume your app's user or tenant model.
- **Routes are opt-in and authenticated by default.** `voice.routes.enabled` defaults to `false`; once enabled, `auth` stays in the middleware list unless you deliberately remove it, and guest access needs a second, explicit flag (`voice.routes.allow_guest`) on top of that.
- **Errors are redacted before they reach the client.** A provider-side failure over HTTP returns a generic "temporarily unavailable" message and a 502 — the real exception is still logged server-side via `report()`, never echoed back.
- **Double-checked upload limits.** The HTTP layer validates file size/type before touching disk; the STT provider re-checks independently, so a bypassed or custom-built controller still can't push an oversized file through to a billed API call.

If you find a security issue, please report it privately rather than as a public GitHub issue.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Tests use `Http::fake()` against the real provider URL patterns (mirroring LaravelEasyAI's own test conventions) — no live API calls are made.

## License

MIT.
