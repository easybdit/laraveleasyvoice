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
    */
    'routes' => [
        'enabled' => env('VOICE_ROUTES_ENABLED', false),
        'prefix' => env('VOICE_ROUTES_PREFIX', 'voice'),
        'middleware' => ['web', 'auth'],

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

];
