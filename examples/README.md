# Examples

Three complete, drop-in pages showing this package's three real usage modes end to end - not just the code snippets in the main [README.md](../README.md), but full working pages you can run in a real app in a few minutes.

| File | Shows | Requires |
|---|---|---|
| [`turn-based-widget.blade.php`](turn-based-widget.blade.php) | The opt-in HTTP API (`voice-widget.js`) - hold-to-talk, transcript bubbles, level meters, a polished default UI | `voice.stt`/`voice.tts` configured (any provider) |
| [`realtime-openai.blade.php`](realtime-openai.blade.php) | Browser-direct realtime voice over WebRTC (`voice-realtime.js`) | A real OpenAI account with Realtime API + billing enabled |
| [`realtime-deepgram.blade.php`](realtime-deepgram.blade.php) | Browser-direct realtime voice over WebSocket+PCM (`voice-realtime-deepgram.js`) | A Deepgram account with an Owner/Admin-role key |

All three are live-tested against real provider accounts, not just written to match documentation - see [CHANGELOG.md](../CHANGELOG.md) for exactly what was verified and when.

## Setup

1. **Install the package and publish its assets** (in your host app, not this repo):

   ```bash
   composer require easybdit/laraveleasyvoice
   php artisan voice:install
   php artisan vendor:publish --tag=voice-assets
   ```

2. **Copy the pages you want** into your app's views, e.g. under a `voice-examples` namespace:

   ```bash
   mkdir -p resources/views/voice-examples
   cp vendor/easybdit/laraveleasyvoice/examples/*.blade.php resources/views/voice-examples/
   ```

3. **Register an agent** (for the turn-based widget) in a service provider's `boot()` method:

   ```php
   use EasyAI\LaravelVoice\Facades\Voice;

   Voice::registerAgent('receptionist', function ($agent) {
       $agent->stt('openai')->tts('openai')->llm('openai')
           ->systemPrompt('You are a friendly front-desk receptionist.')
           ->limits(maxTurns: 50, maxSessionSeconds: 1800); // cost/abuse guardrails - see README's Security & Trust section
   });
   ```

   The realtime pages don't need a registered agent - they talk to the provider's own realtime API directly once a token is minted; see [`RealtimeVoiceProvider`'s docblock](../src/Contracts/RealtimeVoiceProvider.php) for why.

4. **Add routes** - see [`routes.php`](routes.php) for the exact lines, or wire your own.

5. **Set the relevant `.env` values** - see the main README's "Quickstart" and "Realtime voice" sections for what each provider needs (`VOICE_OPENAI_API_KEY`, `VOICE_REALTIME_DEEPGRAM_API_KEY`, etc.).

6. **Enable the routes** this package itself needs:

   ```env
   VOICE_ROUTES_ENABLED=true
   VOICE_REALTIME_ENABLED=true   # only if trying a realtime example
   ```

   By default these routes require an authenticated user (`auth` stays in the middleware list). For a quick local try without logging in first, set `VOICE_ROUTES_ALLOW_GUEST=true` and `VOICE_ROUTES_REQUIRE_AUTH=false` - **never leave that on in production**, see the main README's Security & Trust section.

## Notes

- These pages are intentionally plain HTML/vanilla JS with inline `<style>`/`<script>` - no build step, no framework assumption, matching this package's own "zero dependency" posture for its JS clients.
- `turn-based-widget.blade.php` is the most polished of the three (a real UI, not just buttons and a log) - copy its structure if you're building a production widget.
- The two realtime pages are intentionally minimal (buttons + a raw event log) since they're meant to demonstrate the client classes' API, not be a finished product UI.
- None of these three files are loaded or referenced by the package itself - they're examples to copy, not part of the package's runtime.
