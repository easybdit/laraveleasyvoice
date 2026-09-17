# Changelog

## Unreleased — working toward v0.1.0

This package is pre-release: no tagged version, not yet on Packagist. Entries below are logged by build phase rather than version number until v0.1.0 ships, so the history of *why* each piece exists survives even though nothing has a tag yet.

### 📦 Packaging fix before first publish: a local-only dependency path would have broken every install

`composer.json` pointed at `easybdit/laraveleasyai` through a `repositories: [{"type": "path", "url": "../2.Laravel_EasyAI_Package_Development"}]` entry and a `"*"` version constraint — convenient for developing both packages side by side on one machine, but it silently assumes that sibling directory exists. Cloned from GitHub or installed via Packagist by anyone else, `composer install` would have failed outright. Fixed before the first push: confirmed `easybdit/laraveleasyai` is genuinely live on Packagist (`composer show easybdit/laraveleasyai --all`, latest published `2.19.0` — the local sibling checkout is ahead of what's actually released, at `2.20.1`), dropped the path repository, and pinned the constraint to `^2.14`, the version that actually introduced the `run()` agent-loop signature this package depends on (tool-calling + the `$onToolCall`/`$onChunk` callbacks), rather than requiring a version newer than what's published. Re-ran `composer install` from a clean `vendor/` against the real Packagist copy and the full suite (23 tests, 52 assertions) still passes — this package's actual compatibility floor is now verified, not assumed.

Also removed a `config.audit.block-insecure`/`policy.advisories.block: false` block that had been carried over from LaravelEasyAI's own `composer.json` without a matching reason — that suppression made sense there for a specific advisory already assessed; copying it here would have silently muted Composer's own security-audit warnings for a package with no such assessment behind it.

### 🌐 Phase 3: HTTP routes — sessions, turns, and audio playback

The first public attack surface this package ships: `POST /voice/sessions`, `POST /voice/sessions/{id}/end`, `POST /voice/sessions/{id}/turns` (multipart audio upload → transcript + tool calls + an audio URL), and `GET /voice/sessions/{id}/turns/{turn}/audio` (private-disk playback). All four are **disabled by default** (`voice.routes.enabled = false`) and, when enabled, run under `['web', 'auth']` by default — every request here triggers billed STT/LLM/TTS calls, so unlike a text-chat widget there is no safe "open by default" posture.

Identity is resolved by a new `VoiceIdentity` class rather than reusing LaravelEasyAI's `ChatIdentity`, even though the pattern (signed guest cookie, overridable resolver, rate-limit key) is copied from it almost verbatim — deliberately, so that a host app's `ai.chat.access.allow_guest` setting can never silently affect voice access. Guest access defaults to **off** here (`voice.routes.allow_guest = false`), stricter than LaravelEasyAI's chat default of `true`, because a voice request costs more per call. Every session-scoped route (`end`, `turns`, `turns/.../audio`) goes through `AuthorizesVoiceSession`, which calls the fail-closed `VoiceSession::isOwnedBy()` added in Phase 2 — a 403, not a redirect or a 404, for anyone who isn't the session's owner.

Upload validation happens twice, deliberately: the controller rejects an oversized/wrong-mime file via Laravel's own validator (`voice.routes.max_upload_kb`) before touching the disk, and `OpenAiSttProvider` (Phase 1) independently re-checks the file size as defense in depth if this endpoint is ever bypassed by a different caller. A failed turn maps to a specific status instead of a blanket 500: `VoiceLimitExceededException` → 429, a provider-side failure → 502 with a generic message (the real exception is still `report()`ed server-side, never echoed to the client), a bad/empty input → 422, any other `VoiceException` (e.g. "session not active") → 409.

Two real bugs found while writing the HTTP tests, both about Laravel testing internals rather than this package's own logic, but worth knowing:
- **`postJson()`/`deleteJson()` silently drop cookies unless `withCredentials()` is also called** (`MakesHttpRequests::prepareCookiesForJsonRequest()` returns `[]` otherwise) — a guest-cookie test can pass a cookie to `withCookies()` and still have the server see an anonymous request. LaravelEasyAI's own `ChatFlowTest` chains `withCredentials()->withCookies([...])` for exactly this reason; the Voice guest tests were fixed to match.
- **A validation failure on a plain `post()` redirects (302) instead of returning JSON** unless the request declares `Accept: application/json` — irrelevant for `postJson()`, but file uploads can't go through `postJson()`'s json-encoded body, so a plain `post()` with an explicit `Accept` header is needed to assert a 422 instead of a redirect.

23 tests, 52 assertions, all passing — including the full authenticated flow, ownership checks on both the `end` and audio-playback endpoints, the oversized-upload rejection, and all three guest-cookie scenarios (first-time mint, returning guest, cross-guest isolation).

### 🎙️ Phase 2: Session/turn persistence + the `VoiceAgent` orchestrator

The first piece that actually behaves like a voice agent rather than a pair of HTTP clients: `Voice::registerAgent('name', fn ($agent) => ...)` registers a named agent (drivers, tools, system prompt, limits) from a service provider's `boot()` — deliberately not from `config/voice.php`, since tool handlers are closures and `config:cache` `var_export()`s the config array, which cannot serialize one. `Voice::agent('name')->startSession(...)->handleTurn($session, $audioPath)` then runs one full exchange: STT → `AI::provider(...)->tools(...)->run(...)` (LaravelEasyAI's existing agent loop, untouched) → TTS, persisting a `voice_sessions`/`voice_turns` row pair and firing 7 new events (`SessionStarted`, `SessionEnded`, `SpeechTranscribed`, `ToolCallStarted`, `ToolCallCompleted`, `ResponseSynthesized`, `VoiceError`) via Laravel's `Event` facade — LaravelEasyAI fires none at all, confirmed by reading its source, so this is genuinely new capability, not a reimplementation.

Two real bugs found and fixed while writing the first tests against this, both worth knowing if you extend this code:

- **Laravel's `Gate` refuses a zero-argument ability closure for a guest (unauthenticated) caller** — `Gate::define('x', fn () => true)` silently denies every guest request, not because the logic says so, but because `Gate::callbackAllowsGuests()` requires the closure's first parameter to exist and be nullable/defaulted. Every ability closure needs `fn ($user = null) => ...`. This is safe (fail-closed), but easy to misdiagnose as "authorization isn't working" — documented in `AuthorizedTool` and in the tests.
- **`AbstractDriver::run()` (LaravelEasyAI's agent loop) returns only the final step's response.** Once the loop converges to a plain text answer, that response's own `getToolCalls()` is empty by definition — the tool calls made earlier in the loop are only ever observable through the `$onToolCall` callback. `VoiceAgent::handleTurn()` accumulates them from the callback rather than reading them off the returned response.

Security decisions baked into this phase, all as defaults rather than opt-in:
- `VoiceSession::isOwnedBy()` **fails closed** — a session with no verifiable matching identity is never treated as accessible. This is a deliberate departure from LaravelEasyAI's own `ChatSession::isOwnedBy()`, which allows access when no identity was recorded, for backward compatibility with data predating its identity columns. There is no such legacy data here, and voice sessions carry both cost-bearing provider calls and recorded speech, so the safer default was chosen instead.
- `tenant_id`/`user_id`/`guest_token` on `voice_sessions` are nullable and carry **no foreign key**, mirroring LaravelEasyAI's own `ai_chat_sessions` — this package cannot assume the host app's user/tenant model.
- `limits(maxTurns, maxSessionSeconds)` is enforced by default on every turn (not opt-in) and actually ends the session once exceeded, blocking further turns — a voice turn costs real STT+LLM+TTS money per call, so silent unbounded usage was never an acceptable default.
- Synthesized audio is always written through the configured private disk (`voice.storage.disk`, default `local`) — never a public disk.

13 tests, 33 assertions, all passing (`vendor/bin/phpunit`), covering a full end-to-end turn, tool-calling through `AuthorizedTool`, and the limit guard actually ending a session.

### 🏗️ Phase 1: Package foundation — STT/TTS contracts, an OpenAI provider, and tool authorization

Established after auditing `easybdit/laraveleasyai`'s actual source (not its README) to confirm what genuinely doesn't exist there yet: no STT/TTS abstraction (only a blocking, OpenAI-only `transcribe()`/`textToSpeech()` on `OpenAIDriver`, outside its stable `AIProviderInterface`), no realtime/WebSocket layer, no session/turn concept, no events, no interruption/cancellation. Everything below is new, not duplicated.

- `Contracts\SpeechToTextProvider` / `Contracts\TextToSpeechProvider` — the abstractions LaravelEasyAI never had; `Providers\Stt\OpenAiSttProvider` and `Providers\Tts\OpenAiTtsProvider` are the first (only) implementations, deliberately mirroring `OpenAIDriver`'s own `Http::` conventions rather than inventing a different style.
- `VoiceManager` (`Voice` facade) — a Laravel `Manager`-pattern registry for `stt()`/`tts()` drivers, the same pattern LaravelEasyAI's own `AIManager` uses for LLM providers.
- `Agent\AuthorizedTool` — wraps LaravelEasyAI's `Tool` so a voice command can never reach a handler without an explicit `Gate::allows($ability)` check, failing closed on denial. This exists because a voice agent turns *spoken, untrusted* input into function calls; LaravelEasyAI's own `Tool` has no such check built in, and shouldn't need one for its own text-chat use case.

Security decisions baked into this phase:
- STT rejects any file over a configurable size cap (default 25MB, OpenAI's own ceiling) **before** any network call — no cost or DoS exposure from an oversized upload.
- TTS rejects any text over a configurable length cap before calling out, same reasoning.
- Retries cover connection failures only, never non-2xx responses — a bad request or auth failure is never silently retried into extra billed calls.
- No remote-URL ingestion path anywhere — only local file paths accepted, so there's no SSRF surface to design around later.
- A dedicated `Exceptions\VoiceException` hierarchy, decoupled from LaravelEasyAI's own — a Voice failure is never disguised as an `EasyAI\LaravelAI` exception.

10 tests, 16 assertions, all passing.

### Deliberately not built yet

No realtime/WebSocket transport, no interruption/barge-in, no telephony, no non-OpenAI providers. These are sequenced, not forgotten — each is a larger, harder-to-reverse decision (long-lived connections, provider lock-in, public phone numbers) than anything shipped so far, and gets designed and reviewed on its own rather than bundled in.
