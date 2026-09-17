<h1 align="center">LaravelEasyVoice</h1>

<p align="center">
  <strong>AI voice agents for Laravel, built on top of LaravelEasyAI.</strong><br>
  Speech-to-text, text-to-speech, voice sessions, and voice-enabled tool-calling agents.
</p>

<p align="center">
  <img src="https://img.shields.io/packagist/v/easybdit/laraveleasyvoice.svg?style=flat-square&label=version" alt="Latest Version">
  <img src="https://img.shields.io/badge/license-MIT-blue?style=flat-square" alt="License">
  <img src="https://img.shields.io/badge/php-%5E8.1-777bb4?style=flat-square" alt="PHP Version">
</p>

<p align="center">
  Built by <a href="https://www.easyit.com.bd">Easy IT</a>
</p>

---

> **v0.1.0 is published on Packagist.** This README documents what's actually implemented today, including work that has landed on `main` since v0.1.0 tagged — see [CHANGELOG.md](CHANGELOG.md) for the phase-by-phase build history and the reasoning behind each design decision. Following v0.1's own cadence, `main` accumulates changes across several phases before the next version tag, rather than tagging every phase.

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
php artisan voice:install
```

`voice:install` publishes `config/voice.php`, runs migrations (with your confirmation), configures your OpenAI key (reusing `OPENAI_API_KEY` automatically if LaravelEasyAI already set one — no need to enter it twice), and asks whether to turn on the HTTP API. Prefer to do it by hand instead:

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

- **`Voice::stt($driver)` / `Voice::tts($driver)`** — provider-agnostic speech contracts (`SpeechToTextProvider`, `TextToSpeechProvider`), with OpenAI, Deepgram (STT), and ElevenLabs (TTS) implementations.
- **`Voice::registerAgent()` / `Voice::agent()`** — named, reusable voice agents that wire STT → LaravelEasyAI's `AI::provider()->tools()->run()` agent loop → TTS into one call.
- **Sessions & turns** (`voice_sessions`, `voice_turns`) — persisted history per caller, with token/latency accounting on the session.
- **Events** — `SessionStarted`, `SessionEnded`, `SpeechTranscribed`, `ToolCallStarted`, `ToolCallCompleted`, `ResponseSynthesized`, `VoiceError`, all fired through Laravel's own `Event` facade.
- **`AuthorizedTool`** — a tool wrapper that structurally requires a `Gate` ability check before its handler ever runs, failing closed on denial or an undecidable guest check.
- **HTTP routes (opt-in)** — `POST /voice/sessions`, `POST /voice/sessions/{id}/end`, `POST /voice/sessions/{id}/turns` (audio in, transcript + audio URL out), `GET /voice/sessions/{id}/turns/{turn}/audio`. See below.
- **`php artisan voice:install`** — guided setup: publishes config/migrations, configures the OpenAI key, asks about the HTTP API.
- **Streaming text responses (opt-in)** — `VoiceAgent::streamResponses()` fires a `ResponseChunkReceived` event per delta as the LLM's reply streams in, for showing text progressively before the full turn (including TTS) finishes.
- **Multi-tenancy wiring** — `voice.routes.tenant_resolver` resolves the caller's tenant per request; every session-scoped HTTP route enforces it via `VoiceSession::isOwnedBy()`.
- **Tool tiers** — `AuthorizedTool::make(..., tier: 'destructive')` (`read`/`write`/`destructive`/`privileged`) surfaces on `ToolCallStarted`/`ToolCallCompleted` for auditing, alongside the `Gate` check that actually authorizes the call.
- **Chat-history mirroring (opt-in)** — link a session to an existing `ai_chat_sessions` row (`chat_session_id`) and every turn is also written to `ai_chat_messages`, so a voice call and a text chat can share one transcript.
- **`Analytics\VoiceUsage`** — a query service over `voice_sessions`/`voice_turns` for aggregate or per-session usage (STT/TTS duration, tokens, estimated cost, latency), for building your own admin view or a future billing layer.
- **Human handoff** — `VoiceAgent::requestHandoff()`/`completeHandoff()` fire `HandoffRequested`/`HandoffCompleted` events for notifying a human agent through whatever channel you already use.
- **Cross-session memory (opt-in tools)** — `RememberFactTool`/`RecallFactTool` give an agent a small per-caller key-value fact store that survives across separate sessions, gated by the same `Gate`-based authorization as any other tool.
- **Browser widget** (`voice-widget.js`, opt-in, zero dependencies) — a small vanilla-JS client for the HTTP API: start a session, record with `MediaRecorder`, upload the turn, play the reply. See "Browser voice agent" in the Cookbook below.
- **Realtime ephemeral token endpoint (opt-in)** — `POST /voice/realtime/token` mints a short-lived OpenAI Realtime API client token server-side, so your real key never reaches the browser. This is intentionally the full extent of this package's realtime support today — see "Realtime" below for why.
- **`RealtimeVoiceProvider`/`RealtimeConnection`** — design-stage contracts only, not implemented by anything yet. See "Not implemented yet" below.

With this, every item in the original v0.1 scope is implemented — see [CHANGELOG.md](CHANGELOG.md) for the full build history.

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

## Streaming text responses (opt-in)

```php
Voice::registerAgent('receptionist', function ($agent) {
    $agent->stt('openai')->tts('openai')->llm('openai')->streamResponses();
});
```

```php
Event::listen(ResponseChunkReceived::class, function ($event) {
    // $event->chunk  -> the partial text delta
    // $event->type   -> 'content' or 'thinking' (reasoning-capable models)
    broadcast(new YourOwnBroadcastEvent($event->session->id, $event->chunk));
});
```

Text only — TTS still synthesizes the complete reply once at the end of the turn, since none of the shipped TTS providers support streaming audio output. Full duplex audio streaming is a realtime-transport feature, tracked separately below.

## Cookbook

Real, runnable patterns for common voice-agent shapes. Everything below is composition of what's already documented above — none of it needed new package code, which is itself the point: LaravelEasyVoice orchestrates, LaravelEasyAI thinks.

**Basic voice assistant**

```php
Voice::registerAgent('assistant', function ($agent) {
    $agent->stt('openai')->tts('openai')->llm('openai')
        ->systemPrompt('You are a helpful voice assistant. Keep replies short - they will be spoken aloud.');
});
```

**Tool-calling (customer support: "Where is my order?")**

```php
Voice::registerAgent('support', function ($agent) {
    $agent->stt('openai')->tts('openai')->llm('openai')->tools([
        AuthorizedTool::make(
            name: 'get_order_status',
            description: 'Look up the shipping status of an order by its order number',
            parameters: ['type' => 'object', 'properties' => [
                'order_number' => ['type' => 'string'],
            ], 'required' => ['order_number']],
            ability: 'view-own-orders',
            handler: fn (array $args) => Order::where('number', $args['order_number'])
                ->where('user_id', auth()->id()) // never trust the model to scope this itself
                ->firstOrFail()
                ->only(['status', 'estimated_delivery']),
        ),
    ]);
});
```

**RAG voice agent ("What is the admission policy?")** — no new API, just call `AI::rag()` from inside a tool or bake it into the system prompt per turn:

```php
Voice::registerAgent('school-info', function ($agent) {
    $agent->stt('openai')->tts('openai')->llm('openai')->tools([
        AuthorizedTool::make(
            name: 'search_school_policies',
            description: 'Search the school knowledge base (admissions, fees, routine, policies)',
            parameters: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            ability: 'view-public-info', // define this Gate to always allow - it's public information
            handler: fn (array $args) => AI::rag()->source('school-kb')->search($args['query']),
        ),
    ]);
});
```

**Appointment/booking, with read vs. destructive tools distinguished**

```php
Voice::registerAgent('booking', function ($agent) {
    $agent->stt('openai')->tts('openai')->llm('openai')
        ->systemPrompt('Before booking, always call check_availability first. Only call create_appointment after the user has explicitly confirmed the specific time.')
        ->tools([
            AuthorizedTool::make(
                name: 'check_availability',
                description: 'Check open appointment slots for a given date',
                parameters: ['type' => 'object', 'properties' => ['date' => ['type' => 'string']], 'required' => ['date']],
                ability: 'view-availability',
                handler: fn (array $args) => Appointment::availableSlots($args['date']),
                tier: AuthorizedTool::TIER_READ,
            ),
            AuthorizedTool::make(
                name: 'create_appointment',
                description: 'Book a confirmed appointment slot',
                parameters: ['type' => 'object', 'properties' => [
                    'date' => ['type' => 'string'], 'time' => ['type' => 'string'],
                ], 'required' => ['date', 'time']],
                ability: 'create-appointment',
                handler: fn (array $args) => Appointment::book(auth()->id(), $args['date'], $args['time']),
                tier: AuthorizedTool::TIER_WRITE,
            ),
        ]);
});
```

**School AI receptionist** — a single agent combining the RAG-info and booking patterns above, plus a couple more read-only tools (`check_attendance`, `get_class_routine`, `get_fee_status`), each gated by its own ability. No new capability needed; it's the same composition at a larger scale.

**Multilingual** — pass the caller's language into both STT and TTS per call, and let the model reply in kind:

```php
$agent->handleTurn($session, $audioPath, [
    'stt' => ['language' => 'bn'],       // ISO-639-1: bn, en, hi, ur, ar, ...
    'tts' => ['voice' => 'alloy'],
]);
```

```php
Voice::registerAgent('multilingual', function ($agent) {
    $agent->stt('openai')->tts('openai')->llm('openai')
        ->systemPrompt('Always reply in the same language the user spoke in.');
});
```

Automatic language *detection* (rather than the caller specifying it) isn't built - Whisper can auto-detect if `language` is omitted, but nothing here inspects the transcript to switch the reply language or TTS voice automatically yet.

**Memory** — within one session, prior turns are already included as context (`contextTurns()`, default 10) — "My name is Murad" followed by "What's my name?" works today as long as both are in the same call. For the same fact to survive a *separate* call (the caller phoning back next week), opt an agent into the two memory tools:

```php
Voice::registerAgent('receptionist', function ($agent) {
    $agent->stt('openai')->tts('openai')->llm('openai')
        ->systemPrompt('If the caller shares their name or a preference, call remember_fact. If they ask you to recall something, call recall_fact first.')
        ->tools([
            RememberFactTool::make(),
            RecallFactTool::make(),
        ]);
});
```

This is a small, flat per-caller key-value store (`voice_memories`), not a general memory/retrieval system — no embeddings, no staleness handling, nothing is captured automatically. A session with no identity at all (no tenant, no user, no guest token) can't use it, since there'd be nothing to scope the fact to.

**Human handoff**

```php
// Inside a tool's handler, once the agent recognizes it can't help:
$agent->requestHandoff($session, 'Caller wants a refund - outside this agent\'s scope.');
```

```php
Event::listen(HandoffRequested::class, function ($event) {
    // Notify a human however your app already does - Slack, a support queue, email.
    Notification::route('slack', config('services.slack.support_channel'))
        ->notify(new VoiceHandoffRequested($event->session, $event->reason));
});
```

Once the human has actually taken over, call `$agent->completeHandoff($session, $reason)` to end the session with the reason recorded in its `metadata`.

**Tenant-scoped multi-agent SaaS**

```php
// config/voice.php (or .env-driven, same pattern as identity_resolver)
'tenant_resolver' => fn ($request) => $request->user()?->current_tenant_id,
```

Every session created through the HTTP API now carries `tenant_id`, and `VoiceSession::isOwnedBy()` enforces it on every subsequent request to that session - a 403 for a mismatched tenant, not just a mismatched user.

**Browser voice agent**

```env
VOICE_ROUTES_ENABLED=true
```

```html
<script src="/vendor/laraveleasyvoice/voice-widget.js"></script>
<meta name="csrf-token" content="{{ csrf_token() }}">

<button id="talk">Hold to talk</button>
<script>
  const widget = new VoiceWidget({
      agent: 'receptionist',
      onStateChange: (state) => console.log('state:', state), // idle, starting, ready, recording, uploading, speaking, ended
      onResponse: (turn) => console.log('assistant said:', turn.transcript),
      onError: (error) => console.error(error),

      // Optional - live feedback for a nicer UI, all off by default:
      onLevel: (level) => {}, // 0-1 mic input amplitude, ~60fps, while recording
      onPlaybackLevel: (level) => {}, // 0-1 reply-audio amplitude, ~60fps, while speaking
      onUploadProgress: (percent) => {}, // 0-100 while the recorded turn uploads
  });

  const button = document.getElementById('talk');

  (async () => {
      await widget.start();

      button.addEventListener('mousedown', () => widget.startRecording());
      button.addEventListener('mouseup', () => widget.stopRecording());
  })();
</script>
```

Publish the widget file first: `php artisan vendor:publish --tag=voice-assets`. The widget only talks to this package's own HTTP API (already covered by the test suite above) — it ships no third-party code. `widget.stopSpeaking()` stops local playback immediately (a "stop talking" control), but note it is **not** server-side barge-in — see the note in the widget's own file, and "Realtime" below.

The `uploading` state covers the entire server round-trip (upload + STT + the LLM turn + TTS), not just the HTTP upload itself - there's no separate "server is thinking" state, since how long each provider call takes varies per turn. To show that distinction in your own UI, watch for `onUploadProgress` reaching 100 while `onResponse`/`onTranscript` haven't fired yet - that gap *is* "still processing," without the widget guessing at a label for it.

<details>
<summary>Troubleshooting: "Turn failed" / a provider 401 that only happens through the browser, never from the CLI</summary>

If `php artisan tinker` or `vendor/bin/phpunit` can reach your STT/TTS provider fine but the widget can't, check whether your `php artisan serve` process is older than your last `.env` edit. Laravel's `.env` loader does not override a variable that's already present in the process environment - if the shell you launched `serve` from had already exported an (empty or stale) value for one of these keys, `.env`'s value is silently ignored for the lifetime of that server process, even though `config:show`/tinker from a clean shell resolve it correctly. Restarting `php artisan serve` from a shell without that variable set fixes it.

</details>

**Realtime (ephemeral token only)**

```env
VOICE_REALTIME_ENABLED=true
VOICE_REALTIME_OPENAI_VOICE=alloy
```

```js
const response = await fetch('/voice/realtime/token', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json'},
    body: JSON.stringify({voice: 'alloy'}),
});
const {token} = await response.json(); // an "ek_..." token, expires quickly

// Hand this token to OpenAI's own official realtime client library/quickstart
// to open the actual WebRTC connection - this package's job ends here.
```

This is the one server-side step a browser-direct realtime integration genuinely needs (never expose your real API key to the browser) — not a realtime connection itself. Building the actual duplex audio connection is left to OpenAI's own official client tooling rather than reimplemented here; see "Not implemented yet" for why.

## Not implemented yet

**A full realtime voice connection.** `Contracts\RealtimeVoiceProvider`/`RealtimeConnection` exist as design-stage contracts only — no class implements either, and both docblocks say so. *How* Laravel should host a long-lived duplex connection at all (Octane+Reverb relaying in-process, an external relay service, or the browser-direct approach the token endpoint above enables) is an infrastructure decision this package won't presume on your behalf. **Barge-in/interruption** needs that same realtime connection to mean anything server-side (there's nothing to cancel mid-generation without one) — the widget's `stopSpeaking()` is a client-side approximation only. **Telephony/SIP** is deliberately untouched — it also carries call-recording-consent obligations that belong to your application, not this package. These are sequenced, not overlooked — each is a larger, harder-to-reverse decision than anything shipped so far. See [CHANGELOG.md](CHANGELOG.md) for the full reasoning.

## Security & Trust

This package assumes it will run in front of real, paid, third-party APIs and handle personal (often biometric) voice data, in applications it doesn't control the tenancy model of. Concretely, as of this phase:

- **Fail-closed authorization.** `AuthorizedTool` never invokes its handler without a passing `Gate::allows()` check; an undecidable check (e.g. a guest caller against an ability closure that doesn't declare a nullable `$user` parameter) is denied, not allowed.
- **Fail-closed ownership.** `VoiceSession::isOwnedBy()` denies access to any session without a verifiable matching identity — there's no "no identity recorded, so allow" fallback.
- **Cost and abuse limits are on by default.** `VoiceAgent::limits()` bounds both turns-per-session and session duration, and actually ends the session once exceeded — a voice turn triggers billed STT, LLM, and TTS calls, so unbounded usage was never treated as a safe default.
- **No SSRF surface.** STT only ever reads a local file path you already control; there is no remote-URL ingestion anywhere in this package.
- **Size limits before network calls.** Both STT (file size) and TTS (text length) reject oversized input before making any outbound request.
- **Private storage by default.** Synthesized audio is written to `voice.storage.disk` (default `local`), never a public disk.
- **No invented tenancy, but real enforcement once you plug in a resolver.** `tenant_id`/`user_id`/`guest_token` are nullable, FK-less columns — the same posture LaravelEasyAI itself takes, because this package cannot assume your app's tenant model — but once `voice.routes.tenant_resolver` is set, it's actually checked on every request, not just stored.
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
