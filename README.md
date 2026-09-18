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

> **v0.2.0 is published on Packagist.** This README documents what's actually implemented today. See [CHANGELOG.md](CHANGELOG.md) for the phase-by-phase build history and the reasoning behind each design decision — `main` accumulates changes across several phases before each version tag, rather than tagging every phase.

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
- **Browser widget** (`voice-widget.js`, opt-in, zero dependencies) — a small vanilla-JS client for the HTTP API: start a session, record with `MediaRecorder`, upload the turn, play the reply, live mic/playback level meters, upload progress. See "Browser voice agent" in the Cookbook below.
- **Realtime voice, browser-direct, provider-selectable (opt-in)** — `voice.realtime.default`/`voice.realtime.providers.<name>`, same pattern as `voice.stt`/`voice.tts`. `/voice/realtime/token` mints a short-lived token server-side, live-verified against both **OpenAI** and **Deepgram** real accounts. Each provider has its own browser client, since the two protocols are genuinely different transports: `voice-realtime.js` (`VoiceRealtimeSession`) speaks OpenAI's WebRTC + its own event schema; `voice-realtime-deepgram.js` (`VoiceRealtimeDeepgramSession`) speaks Deepgram's raw WebSocket + linear16 PCM protocol, hand-rolled and grounded in Deepgram's own official SDK source rather than prose docs alone. Both open the connection directly from the browser (Laravel is never in the audio path) and expose an `interrupt()` control. Requesting an unsupported provider fails clearly (422) rather than silently misconnecting. Neither browser connection can be exercised by this package's test suite — see "Realtime voice" in the Cookbook for the honest testing caveat, and its "why a second provider isn't just a config entry" for what a *third* provider would actually need.
- **`RealtimeVoiceProvider`/`RealtimeConnection`** — design-stage contracts only, not implemented by anything yet (the browser-direct clients above don't need them for OpenAI/Deepgram specifically). See "Not implemented yet" below.

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

Every session-scoped route 403s for anyone who isn't the session's owner (`VoiceSession::isOwnedBy()`). To allow anonymous callers, set both `VOICE_ROUTES_ALLOW_GUEST=true` **and** `VOICE_ROUTES_REQUIRE_AUTH=false` in `.env` — a long-lived signed cookie (separate from LaravelEasyAI's own guest cookie) identifies a returning guest, same pattern LaravelEasyAI uses for its chat widget, deliberately kept as an independent config surface so the two packages' access policies can never silently affect each other. `VOICE_ROUTES_REQUIRE_AUTH` is deliberately an env var, not something you hand-edit in the `middleware` array of the published config file — a local test toggle that lives in a config file gets silently lost the next time that file is republished (`vendor:publish --force`), which is exactly the mistake that happened while dogfooding this package and is now fixed at the source.

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

Want a complete, drop-in page instead of a snippet? See **[examples/](examples/)** — three full working pages (the turn-based widget with a real polished UI, OpenAI realtime, Deepgram realtime), each live-tested against a real provider account, with setup instructions.

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

**Realtime voice (browser-direct, OpenAI)**

```env
VOICE_REALTIME_ENABLED=true
VOICE_REALTIME_OPENAI_API_KEY=sk-...   # a real OpenAI key - deliberately separate from
                                         # VOICE_OPENAI_API_KEY (voice.stt/tts), which may be
                                         # pointed at a different, OpenAI-compatible provider
VOICE_REALTIME_OPENAI_VOICE=alloy
```

```html
<script src="/vendor/laraveleasyvoice/voice-realtime.js"></script>
<meta name="csrf-token" content="{{ csrf_token() }}">
<button id="start">Start realtime call</button>
<button id="stop">Interrupt</button>
<script>
  const session = new VoiceRealtimeSession({
      onConnected: () => console.log('live'),
      onEvent: (event) => console.log('event:', event.type, event), // transcripts, turn boundaries, etc. - see OpenAI's Realtime event reference
      onRemoteLevel: (level) => {}, // 0-1 amplitude of the model's voice, for a live waveform
      onError: (error) => console.error(error),
  });

  document.getElementById('start').addEventListener('click', () => session.connect());
  document.getElementById('stop').addEventListener('click', () => session.interrupt());
</script>
```

Publish it with the same `voice-assets` tag as the turn-based widget. `VoiceRealtimeSession` mints a token from `/voice/realtime/token`, then opens a `RTCPeerConnection` **directly from the browser to OpenAI** — Laravel is never in the audio path. `interrupt()` sends OpenAI's `response.cancel` event as an explicit "stop" control; note that OpenAI's own server-side voice-activity detection already auto-interrupts a response when it detects you speaking in the default session config, so `interrupt()` is a backstop, not the only thing making this feel like a real conversation.

**Realtime is provider-selectable, like `voice.stt`/`voice.tts`** — `voice.realtime.default` and `voice.realtime.providers.<name>`, same shape, same reason (so you can choose per your own cost/latency/data-residency needs rather than being locked to one vendor). The token endpoint accepts a `provider` parameter (`'openai'` or `'deepgram'`, both live-verified), and each provider has its own browser client class — `VoiceRealtimeSession` (OpenAI, WebRTC) and `VoiceRealtimeDeepgramSession` (Deepgram, WebSocket) — since the two protocols are not close enough to share one class's connection logic. Requesting an unsupported provider from the token endpoint returns a clear 422, rather than silently misconnecting.

<details>
<summary>Realtime voice (browser-direct, Deepgram)</summary>

```env
VOICE_REALTIME_ENABLED=true
VOICE_REALTIME_DEEPGRAM_API_KEY=...   # needs Owner/Admin role (or explicit keys:write scope) -
                                        # a default/member key gets a live 403 INSUFFICIENT_PERMISSIONS,
                                        # confirmed against a real account, since minting a scoped
                                        # key is itself a privileged key-management operation
```

```html
<script src="/vendor/laraveleasyvoice/voice-realtime-deepgram.js"></script>
<meta name="csrf-token" content="{{ csrf_token() }}">
<button id="start">Start realtime call</button>
<button id="stop">Interrupt</button>
<script>
  const session = new VoiceRealtimeDeepgramSession({
      greeting: 'Hello! How can I help you today?',
      onConnected: () => console.log('live'),
      onTranscript: (text, role) => console.log(role, text), // 'user' or 'assistant'
      onEvent: (event) => console.log('event:', event.type, event),
      onLocalLevel: (level) => {}, // 0-1 mic input amplitude
      onRemoteLevel: (level) => {}, // 0-1 agent speech amplitude
      onError: (error) => console.error(error),
  });

  document.getElementById('start').addEventListener('click', () => session.connect());
  document.getElementById('stop').addEventListener('click', () => session.interrupt());
</script>
```

Publish it with the same `voice-assets` tag as the other clients. `VoiceRealtimeDeepgramSession` mints a scoped key from `/voice/realtime/token`, then opens a raw WebSocket **directly from the browser to Deepgram** at `wss://agent.deepgram.com/v1/agent/converse` — Laravel is never in the audio path. By default, the agent's "think" (LLM) stage uses Deepgram-managed `open_ai`/`gpt-4o-mini` — Deepgram bills this through your Deepgram account, so **no separate OpenAI key is required** to use this client at all (override via the `think` option if you want a different provider/model, or your own `agent.think.endpoint` for a fully custom LLM). `listen`/`speak` similarly default to Deepgram's own `nova-3` STT and `aura-2-thalia-en` TTS voice, both overridable.

Every protocol detail here (connection URL, browser auth via `Sec-WebSocket-Protocol` subprotocols, the `Settings` message shape, event names) was verified against Deepgram's own official SDK source code, not prose documentation alone — two independent Deepgram doc pages disagreed with each other on both the URL and the event-naming convention before that cross-check. See the top of `voice-realtime-deepgram.js` for the full list of what was confirmed and how.

**Honesty note on `interrupt()`**: unlike OpenAI's `response.cancel`, Deepgram's protocol has no confirmed explicit "stop the current response" message — `interrupt()` here is a best-effort local mute/playback-clear only. Real barge-in relies on Deepgram's own server-side turn detection reacting to continuous mic streaming, which this client always does, including while the agent is talking.

</details>

<details>
<summary>Why a second realtime provider (e.g. Together AI's Cartesia Sonic) isn't just a config entry</summary>

Realtime/live-voice protocols are **not** standardized the way simple request/response STT/TTS endpoints often are. OpenAI's Realtime API is one integrated WebRTC session that does speech-in, the LLM turn, speech-out, voice-activity detection, and turn-taking, all server-side — which is *why* this package's entire realtime footprint could be "mint a token, then do one WebRTC handshake." Cartesia Sonic (available on Together AI) is a fast, genuinely impressive **streaming text-to-speech** API — but it's one-way (text in, audio out) over a WebSocket, with no speech-in, no LLM orchestration, and no turn-taking of its own.

A Together/Cartesia-based realtime provider is a real project, not a quick addition, and would need, in order:
1. **Streaming STT** — confirmation of whether Together's STT models expose a genuine low-latency streaming/WebSocket mode (not just the batch endpoint this package already uses), verified against current docs.
2. **Cartesia's exact WebSocket protocol** — the literal `wss://` URL and auth format weren't fully confirmed even during this research; needed before writing a client against it.
3. **A place to run turn-taking/VAD/interruption logic** — OpenAI does this server-side, invisibly. Stitching STT + an LLM call + TTS yourself means *this package* (or the host app) has to decide when the user has stopped talking and it's the model's turn to respond, and how to cut off TTS playback mid-stream on interruption. That's a meaningful design decision, not a config value - most likely a `RealtimeConnection` implementation that runs the loop over WebSockets (browser-to-Laravel-to-Together, or browser-direct to Together with a scoped token if Cartesia supports one - unconfirmed).

If/when this gets built, it'll be its own reviewed increment, following the same verify-then-implement discipline as everything else in this README - not merged in as a same-day follow-up to a different feature.

</details>

**What's actually been verified live vs. what hasn't.** `OpenAiRealtimeTokenBroker` and `DeepgramRealtimeTokenBroker` (the server-side token-minting halves) have both been tested against real accounts with real credentials — and that testing genuinely mattered: two documentation-review passes on OpenAI's request body both missed fields a real API call rejected outright (`session.type` was required and missing; `session.voice` doesn't exist, it's nested under `session.audio.output.voice`), and Deepgram's `scopes` field turned out to be required despite being documented as optional. All fixed and re-verified live. **Deepgram's full browser connection has been verified live, end-to-end**: a real browser session against a real Deepgram account, mic audio in, `ConversationText` transcripts for both sides of the conversation, synthesized speech played back, clean disconnect. **OpenAI's WebRTC connection has not** — token minting works, but the connection step itself is still blocked on that particular account's billing (see the 429 troubleshooting note below), separate from anything this package controls. Neither browser connection can be exercised by `vendor/bin/phpunit` at all — there is no way to open a real WebRTC/WebSocket connection or use real microphone/speaker hardware from a PHP test process — so this live-testing distinction matters more than usual here. Expect the occasional event-reliability rough edge on OpenAI's side specifically (`response.cancel`/`conversation.item.truncate` community reports) once its connection is unblocked — that's OpenAI's API surface, not something this package can smooth over.

<details>
<summary>Troubleshooting: connect() fails with "HTTP 429" even though the token mints successfully</summary>

Token minting (`POST /v1/realtime/client_secrets`) and the actual WebRTC connection (`POST /v1/realtime/calls`) are billed/gated differently on OpenAI's side — minting a token can succeed with zero account balance, while the connection itself is the metered part. A `429` specifically at the `connect()` step (not at token minting) almost always means **no billing/credits configured on the OpenAI account**, not genuine rate-limiting or a bug here — confirmed live: minting worked, connecting 429'd, and the account's own dashboard showed `Credit remaining: $0.00` with "Add credits" still an unchecked setup step. Add a small amount of credit on [platform.openai.com/settings/billing](https://platform.openai.com/settings/organization/billing) and retry. (Phase 13's fix to surface OpenAI's real error body makes this diagnosable from the error message itself, rather than a bare status code.)

</details>

## Not implemented yet

**Barge-in reliability guarantees.** Both realtime clients above genuinely open a live, interruptible connection — but whether an interruption always lands cleanly depends on each provider's own server-side behavior, which this package doesn't control; Deepgram's client in particular has no confirmed explicit "cancel" message the way OpenAI's `response.cancel` is (see that client's own docblock). **A PHP-mediated realtime connection** (`Contracts\RealtimeVoiceProvider`/`RealtimeConnection`) remains design-stage only — no class implements either — since the browser-direct approach above makes that unnecessary for OpenAI/Deepgram specifically; it would still matter for a provider without a public browser-direct realtime API. **Telephony/SIP** is deliberately untouched — it also carries call-recording-consent obligations that belong to your application, not this package. These are sequenced, not overlooked. See [CHANGELOG.md](CHANGELOG.md) for the full reasoning.

## Security & Trust

This package assumes it will run in front of real, paid, third-party APIs and handle personal (often biometric) voice data, in applications it doesn't control the tenancy model of. Concretely, as of this phase:

- **Fail-closed authorization.** `AuthorizedTool` never invokes its handler without a passing `Gate::allows()` check; an undecidable check (e.g. a guest caller against an ability closure that doesn't declare a nullable `$user` parameter) is denied, not allowed.
- **Fail-closed ownership.** `VoiceSession::isOwnedBy()` denies access to any session without a verifiable matching identity — there's no "no identity recorded, so allow" fallback.
- **Cost and abuse limits are on by default.** `VoiceAgent::limits()` bounds both turns-per-session and session duration, and actually ends the session once exceeded — a voice turn triggers billed STT, LLM, and TTS calls, so unbounded usage was never treated as a safe default.
- **No SSRF surface.** STT only ever reads a local file path you already control; there is no remote-URL ingestion anywhere in this package.
- **Size limits before network calls.** Both STT (file size) and TTS (text length) reject oversized input before making any outbound request.
- **Private storage by default.** Synthesized audio is written to `voice.storage.disk` (default `local`), never a public disk.
- **No invented tenancy, but real enforcement once you plug in a resolver.** `tenant_id`/`user_id`/`guest_token` are nullable, FK-less columns — the same posture LaravelEasyAI itself takes, because this package cannot assume your app's tenant model — but once `voice.routes.tenant_resolver` is set, it's actually checked on every request, not just stored.
- **Routes are opt-in and authenticated by default.** `voice.routes.enabled` defaults to `false`; once enabled, `auth` stays in the middleware list unless you explicitly set `VOICE_ROUTES_REQUIRE_AUTH=false`, and guest access needs a second, explicit flag (`voice.routes.allow_guest`) on top of that.
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
