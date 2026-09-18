<?php

return [

    /*
    |--------------------------------------------------------------------
    | Speech-to-Text
    |--------------------------------------------------------------------
    |
    | 'default' selects which provider under 'providers' is used when no
    | driver name is passed to Voice::stt(). Each provider entry is a
    | self-contained config array passed straight to that provider's
    | constructor - never share API keys across unrelated services.
    |
    */
    'stt' => [
        'default' => env('VOICE_STT_PROVIDER', 'openai'),

        'providers' => [
            'openai' => [
                'api_key' => env('VOICE_OPENAI_API_KEY', env('OPENAI_API_KEY')),
                'url' => env('VOICE_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model' => env('VOICE_OPENAI_STT_MODEL', 'whisper-1'),
                'timeout' => env('VOICE_OPENAI_STT_TIMEOUT', 60),

                // Hard ceiling on uploaded audio size, enforced before any
                // network call is made. Prevents a single request from
                // tying up a worker or a paid API call on an oversized
                // upload. Default matches OpenAI's own 25MB API limit.
                'max_file_size' => env('VOICE_STT_MAX_FILE_SIZE', 25 * 1024 * 1024),

                // Retries only cover connection-level failures (DNS/timeout/
                // reset), never non-2xx responses - retrying a paid API call
                // on a 4xx would just multiply cost for no benefit.
                'retries' => env('VOICE_STT_RETRIES', 2),
                'retry_sleep_ms' => env('VOICE_STT_RETRY_SLEEP_MS', 250),
            ],

            'deepgram' => [
                'api_key' => env('VOICE_DEEPGRAM_API_KEY'),
                'url' => env('VOICE_DEEPGRAM_BASE_URL', 'https://api.deepgram.com/v1'),
                'model' => env('VOICE_DEEPGRAM_STT_MODEL', 'nova-2'),
                'timeout' => env('VOICE_DEEPGRAM_STT_TIMEOUT', 60),
                'max_file_size' => env('VOICE_STT_MAX_FILE_SIZE', 25 * 1024 * 1024),
                'retries' => env('VOICE_STT_RETRIES', 2),
                'retry_sleep_ms' => env('VOICE_STT_RETRY_SLEEP_MS', 250),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------
    | Text-to-Speech
    |--------------------------------------------------------------------
    */
    'tts' => [
        'default' => env('VOICE_TTS_PROVIDER', 'openai'),

        'providers' => [
            'openai' => [
                'api_key' => env('VOICE_OPENAI_API_KEY', env('OPENAI_API_KEY')),
                'url' => env('VOICE_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model' => env('VOICE_OPENAI_TTS_MODEL', 'tts-1'),
                'voice' => env('VOICE_OPENAI_TTS_VOICE', 'alloy'),
                'format' => env('VOICE_OPENAI_TTS_FORMAT', 'mp3'),
                'timeout' => env('VOICE_OPENAI_TTS_TIMEOUT', 60),

                // OpenAI's own per-request input cap.
                'max_input_length' => env('VOICE_TTS_MAX_INPUT_LENGTH', 4096),

                'retries' => env('VOICE_TTS_RETRIES', 2),
                'retry_sleep_ms' => env('VOICE_TTS_RETRY_SLEEP_MS', 250),
            ],

            'elevenlabs' => [
                'api_key' => env('VOICE_ELEVENLABS_API_KEY'),
                'url' => env('VOICE_ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
                'model' => env('VOICE_ELEVENLABS_MODEL', 'eleven_multilingual_v2'),

                // No usable default - ElevenLabs voice ids are per-account
                // (cloned/library voices), unlike OpenAI's fixed named
                // voices. Must be set explicitly, or passed as the
                // "voice" option per call.
                'voice' => env('VOICE_ELEVENLABS_VOICE_ID'),

                'format' => env('VOICE_ELEVENLABS_FORMAT', 'mp3_44100_128'),
                'timeout' => env('VOICE_ELEVENLABS_TIMEOUT', 60),
                'max_input_length' => env('VOICE_ELEVENLABS_MAX_INPUT_LENGTH', 5000),
                'retries' => env('VOICE_TTS_RETRIES', 2),
                'retry_sleep_ms' => env('VOICE_TTS_RETRY_SLEEP_MS', 250),
            ],

            'deepgram' => [
                // Deliberately the same credential as voice.stt.providers.deepgram
                // (one Deepgram account, one key) - no separate
                // VOICE_DEEPGRAM_TTS_API_KEY exists.
                'api_key' => env('VOICE_DEEPGRAM_API_KEY'),
                'url' => env('VOICE_DEEPGRAM_BASE_URL', 'https://api.deepgram.com/v1'),

                // Deepgram's TTS "model" value IS the voice (e.g.
                // aura-2-thalia-en) - there is no separate voice
                // parameter the way OpenAI/ElevenLabs have one, confirmed
                // against Deepgram's own current API reference. Same
                // default voice already used by voice-realtime-deepgram.js's
                // speak stage, for consistency across the package.
                'model' => env('VOICE_DEEPGRAM_TTS_MODEL', 'aura-2-thalia-en'),

                // Deepgram's real query parameter is "encoding", not
                // "format" - named 'format' here only to match this
                // config array's naming convention with the openai/
                // elevenlabs blocks above; its value is passed straight
                // through as Deepgram's own "encoding" value.
                'format' => env('VOICE_DEEPGRAM_TTS_FORMAT', 'mp3'),

                'timeout' => env('VOICE_DEEPGRAM_TTS_TIMEOUT', 60),

                // Deepgram's own documented per-request character limit
                // (Aura-1 and Aura-2 both): exceeding it returns a 413.
                'max_input_length' => env('VOICE_DEEPGRAM_TTS_MAX_INPUT_LENGTH', 2000),

                'retries' => env('VOICE_TTS_RETRIES', 2),
                'retry_sleep_ms' => env('VOICE_TTS_RETRY_SLEEP_MS', 250),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------
    |
    | Synthesized audio is written here. This must be a PRIVATE disk -
    | voice recordings and their transcripts are personal (often
    | biometric) data. Never point this at the 'public' disk; serve
    | playback through a signed, time-limited URL from your own app.
    |
    */
    'storage' => [
        'disk' => env('VOICE_STORAGE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------
    | HTTP routes
    |--------------------------------------------------------------------
    |
    | Disabled by default. Every request against these routes triggers
    | billed STT/LLM/TTS calls, so - unlike a text chat widget - there is
    | no safe "open by default" posture here. Enabling this opts into a
    | ready-made session/turn API; 'auth' stays in the middleware list
    | unless you deliberately want guest access (and 'allow_guest' below
    | is also true).
    |
    | 'auth' is computed from VOICE_ROUTES_REQUIRE_AUTH (default true)
    | rather than hardcoded in the array below, specifically so toggling
    | it for local testing is a one-line .env change that survives
    | `vendor:publish --force` - hand-editing this array to drop 'auth'
    | for a quick local test, then losing that edit the next time this
    | file gets republished, is exactly the mistake this was added to
    | prevent. For anything beyond that simple on/off - a different guard
    | entirely, e.g. 'auth:sanctum' - edit the array directly; this env
    | var only ever adds or omits the literal string 'auth'.
    |
    */
    'routes' => [
        'enabled' => env('VOICE_ROUTES_ENABLED', false),
        'prefix' => env('VOICE_ROUTES_PREFIX', 'voice'),
        'middleware' => array_values(array_filter([
            'web',
            env('VOICE_ROUTES_REQUIRE_AUTH', true) ? 'auth' : null,
        ])),

        // Only takes effect if 'auth' is removed from 'middleware' above.
        'allow_guest' => env('VOICE_ROUTES_ALLOW_GUEST', false),

        // fn (\Illuminate\Http\Request $request): int|string|null - for a
        // Bearer-token/SPA host app whose caller never carries a Laravel
        // session. NOTE: like LaravelEasyAI's identical ai.chat setting,
        // a Closure here cannot survive `php artisan config:cache` (config
        // files are var_export()'d) - if you need this cached, resolve it
        // through a container binding instead and reference the class name.
        'identity_resolver' => null,

        // fn (\Illuminate\Http\Request $request): int|string|null - resolves
        // the caller's tenant id for a multi-tenant host app. No default
        // implementation is provided (unlike identity_resolver's
        // $request->user() fallback) because there is no single Laravel
        // convention for "current tenant" the way there is for "current
        // user" - leave this null and every session's tenant_id stays
        // null, same as leaving multi-tenancy entirely to the host app.
        // Same config:cache caveat as identity_resolver above.
        'tenant_resolver' => null,

        'throttle' => env('VOICE_ROUTES_THROTTLE', '30,1'),

        'max_upload_kb' => env('VOICE_ROUTES_MAX_UPLOAD_KB', 25600),
    ],

    /*
    |--------------------------------------------------------------------
    | Stale turn recovery
    |--------------------------------------------------------------------
    |
    | A voice turn's owning PHP process can disappear mid-flight (a crash,
    | a killed worker, a server restart) and leave its row stuck at
    | 'pending' forever. A retry sent with the SAME Idempotency-Key as
    | that stuck turn is only ever allowed to reclaim it once it has been
    | 'pending' for longer than this threshold - never sooner, since a
    | genuinely slow (but still alive) attempt must not be mistaken for
    | an abandoned one.
    |
    | There is no safe default this package can compute for you: STT/TTS
    | each have their own configurable timeout above (voice.stt/voice.tts),
    | but the LLM/tool-calling portion's own timeout is owned entirely by
    | whichever LaravelEasyAI provider you've configured, which this
    | package has no reliable way to inspect - guessing it would risk
    | reclaiming a turn that's still genuinely in progress. The 300
    | seconds below is a timestamp-based heuristic, not a computed safe
    | value - it cannot distinguish a crashed process from a legitimately
    | slow one, and 300 is NOT guaranteed to be enough for every turn.
    |
    | Concretely, this package's own SHIPPED DEFAULTS already sum to more
    | than 300 seconds in the worst case, before your own agent's tools
    | are even counted:
    |   - STT: voice.stt's default timeout (60s) x its default retries
    |     (2 total attempts)                          ~120s
    |   - LLM/tool loop: LaravelEasyAI's default per-call timeout (60s)
    |     x VoiceAgent's default maxSteps (5)          ~300s
    |   - TTS: voice.tts's default timeout (60s) x its default retries
    |     (2 total attempts)                          ~120s
    |   ------------------------------------------------------------
    |     Combined shipped provider-timeout budget:    ~540s
    |
    | That ~540s is the sum of the shipped provider-timeout budgets, not
    | a guaranteed absolute ceiling on how long a turn can legitimately
    | run - a real turn will usually finish far faster, but nothing stops
    | it from using its full configured timeout budget on a slow provider
    | or a loaded model. On top of it, each tool call your agent makes
    | runs an arbitrary host-app closure (Tool::execute()) with NO
    | timeout imposed anywhere in this package or in LaravelEasyAI, so
    | real tool-handler latency (a database query, an external API call)
    | can extend the true worst case further still.
    |
    | You must set this above the real maximum time one of your own
    | turns can legitimately take - computed from YOUR OWN configured
    | STT/TTS timeouts and retries, YOUR agent's maxSteps and LLM
    | provider timeout, and YOUR slowest tool handler's expected
    | duration - plus a safety margin, before relying on this in
    | production. Leaving every default untouched is not, by itself, a
    | safe configuration for this setting.
    |
    */
    'turn_recovery' => [
        'stale_after_seconds' => env('VOICE_TURN_STALE_AFTER_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------
    | Realtime (ephemeral token minting only)
    |--------------------------------------------------------------------
    |
    | This package does not implement a realtime voice connection itself
    | - see Contracts\RealtimeVoiceProvider's docblock for why. What it
    | can do safely is the one server-side step a browser-direct realtime
    | integration actually needs: minting a short-lived client token so
    | your real API key never reaches the browser. The browser then
    | connects directly to the provider (e.g. via WebRTC, following that
    | provider's own official client library/quickstart) - this package
    | is not involved in that connection at all.
    |
    | Provider-namespaced the same way voice.stt/voice.tts are, so a
    | second realtime provider can be added later without a breaking
    | change - but note realtime protocols are NOT interchangeable the
    | way simple request/response STT/TTS endpoints often are: OpenAI's
    | is one integrated WebRTC session doing STT+LLM+TTS+turn-taking
    | server-side, while e.g. Cartesia's realtime API is TTS-only
    | (streaming audio out, no speech-in, no turn-taking) over a
    | different transport (WebSocket, not WebRTC). Only 'openai' is
    | implemented today; see resources/js/voice-realtime.js's docblock
    | and README.md's Realtime section for exactly what a second
    | provider would require before it could be added.
    |
    */
    'realtime' => [
        'enabled' => env('VOICE_REALTIME_ENABLED', false),
        'default' => env('VOICE_REALTIME_PROVIDER', 'openai'),

        'providers' => [
            'openai' => [
                // Deliberately its OWN key/URL, not shared with voice.stt/tts's
                // "openai" provider - those are meant to be pointed at any
                // OpenAI-*compatible* endpoint (Together, a proxy, ...) and
                // doing that here too would silently send this real OpenAI
                // account's traffic to a different host. Realtime's WebRTC
                // signaling is not known to be implemented by any
                // OpenAI-compatible provider, so this always targets OpenAI's
                // real API and needs a real OpenAI key even if your STT/TTS
                // key above belongs to a different provider entirely.
                'api_key' => env('VOICE_REALTIME_OPENAI_API_KEY', env('OPENAI_API_KEY')),
                'url' => env('VOICE_REALTIME_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model' => env('VOICE_REALTIME_OPENAI_MODEL', 'gpt-realtime'),
                'voice' => env('VOICE_REALTIME_OPENAI_VOICE', 'alloy'),
                'timeout' => env('VOICE_REALTIME_OPENAI_TIMEOUT', 15),
            ],

            // Token-minting only, same as 'openai' above - the actual
            // realtime connection is a raw WebSocket + linear16 PCM audio
            // protocol, genuinely different from OpenAI's WebRTC + SDP
            // handshake, and is not implemented by any client in this
            // package yet (see Realtime\DeepgramRealtimeTokenBroker's
            // docblock). Only the server-side scoped-key minting exists so
            // far.
            'deepgram' => [
                'api_key' => env('VOICE_REALTIME_DEEPGRAM_API_KEY', env('VOICE_DEEPGRAM_API_KEY')),
                'url' => env('VOICE_REALTIME_DEEPGRAM_BASE_URL', 'https://api.deepgram.com/v1'),

                // Deepgram's scoped-key endpoint is per-project
                // (POST /v1/projects/{project_id}/keys). Left null by
                // default - the broker resolves it automatically via
                // GET /v1/projects using the API key, so most accounts
                // (one project) never need to set this. Only required if
                // your account has more than one project.
                'project_id' => env('VOICE_REALTIME_DEEPGRAM_PROJECT_ID'),

                // Deepgram's own documented maximum for a scoped key.
                'ttl_seconds' => env('VOICE_REALTIME_DEEPGRAM_TTL', 3600),

                'timeout' => env('VOICE_REALTIME_DEEPGRAM_TIMEOUT', 15),
            ],
        ],
    ],

];
