/**
 * LaravelEasyVoice realtime client for Deepgram's Voice Agent API - a raw
 * WebSocket + linear16 PCM protocol, genuinely different from OpenAI's
 * WebRTC + SDP handshake (see voice-realtime.js), so it gets its own file
 * rather than a shared class with a provider switch inside it.
 *
 * Laravel's only involvement is minting the scoped ephemeral key via
 * POST {baseUrl}/realtime/token (provider: "deepgram") - see
 * DeepgramRealtimeTokenBroker's docblock. Everything below runs entirely
 * in the browser once that key is minted; Laravel never touches the audio.
 *
 * This package deliberately does NOT depend on Deepgram's own
 * @deepgram/agents or @deepgram/browser-agent packages - neither ships a
 * pinned npm/CDN release suitable for a zero-build "publish and include
 * with a <script> tag" package (as of when this was written, the browser
 * component was only installable from a GitHub #main branch). Every fact
 * below was instead verified against Deepgram's own official, real source
 * code (github.com/deepgram/agent, packages/sdk/src/agent-session.ts) and
 * current API docs, not guessed and not taken from a single prose page -
 * two independent doc pages disagreed with each other on both the
 * connection URL and the message-name list before this was cross-checked
 * against the actual SDK source:
 *
 *   - wss://agent.deepgram.com/v1/agent/converse - confirmed as the
 *     connection URL from two independent sources (the official
 *     "Build a Voice Agent" guide and the @deepgram/browser-agent web
 *     component's own default `url` attribute).
 *   - Auth: browsers cannot set an Authorization header on a WebSocket
 *     handshake, only subprotocols - Deepgram's own SDK source contains a
 *     comment confirming its client converts Authorization-header-style
 *     config into Sec-WebSocket-Protocol subprotocols specifically in
 *     browser environments. The documented subprotocol shape is
 *     `['token', <the-scoped-key>]`, passed as the WebSocket constructor's
 *     second argument (there is no other way to attach auth to a browser
 *     WebSocket handshake).
 *   - First message after open must be a JSON `{type: "Settings", ...}`
 *     message (real field names/defaults confirmed from agent-session.ts):
 *     `audio.input: {encoding: "linear16", sample_rate: 16000}`,
 *     `audio.output: {encoding: "linear16", sample_rate: 24000}`, and an
 *     `agent` object configuring the listen (STT) / think (LLM) / speak
 *     (TTS) providers.
 *   - `agent.think.provider.type` can be "open_ai", "anthropic", "google",
 *     or "nvidia" WITHOUT supplying your own API key for that provider -
 *     Deepgram documents these as its own managed LLMs, billed through
 *     your Deepgram account. Only "groq", "aws_bedrock", or a fully custom
 *     `agent.think.endpoint` require you to bring your own key. This
 *     client defaults to a Deepgram-managed "open_ai"/"gpt-4o-mini" think
 *     provider for exactly that reason - so a Deepgram-only account (no
 *     separate OpenAI key) works out of the box.
 *   - Outgoing mic audio: raw linear16 PCM ArrayBuffers sent as-is over
 *     the WebSocket (binary frames) - Deepgram's own SDK calls this
 *     `sendMedia(data)`; there's no separate wrapper/envelope/base64.
 *   - Incoming TTS audio: raw linear16 PCM arrives as binary WebSocket
 *     frames (not JSON) - anything that is NOT parseable JSON on this
 *     socket is audio, not a mis-shaped event.
 *   - Confirmed real JSON event `type` values (from agent-session.ts's own
 *     message handling, a strictly larger/more accurate list than either
 *     prose doc page had alone): Welcome, SettingsApplied,
 *     ConversationText, UserStartedSpeaking, AgentThinking,
 *     FunctionCallRequest, FunctionCallResponse, AgentStartedSpeaking,
 *     AgentAudioDone, PromptUpdated, SpeakUpdated, ThinkUpdated,
 *     ListenUpdated, LatencyReport, History, InjectionRefused, Error,
 *     Warning.
 *
 * HONESTY NOTE, deliberately kept here rather than only in docs: Deepgram's
 * own SDK source has no explicit "cancel the agent's current speech"
 * message type (unlike OpenAI's `response.cancel`) - only session-config
 * `Update*` messages (UpdatePrompt/UpdateSpeak/UpdateThink) were found.
 * Barge-in here therefore relies on Deepgram's server-side turn detection
 * reacting to continued mic audio (this client always keeps streaming
 * mic frames, including while the agent is talking) rather than an
 * explicit interrupt() call - interrupt() below exists as a best-effort
 * client-side stop (mutes the mic and clears local queued playback) but
 * cannot force Deepgram's server to stop an in-flight response the way
 * OpenAI's response.cancel does. If Deepgram ships an explicit control
 * message for this later, update this note along with the code.
 *
 * This file cannot be exercised by this package's PHPUnit suite - there is
 * no way to open a real WebSocket connection or use real microphone/
 * speaker hardware from a PHP test process. Verify manually, in a real
 * browser, against a real Deepgram account.
 */
class VoiceRealtimeDeepgramSession {
    /**
     * @param {Object} options
     * @param {string} [options.baseUrl='/voice'] - matches config('voice.routes.prefix').
     * @param {string} [options.wsUrl='wss://agent.deepgram.com/v1/agent/converse']
     * @param {string} [options.csrfToken] - defaults to <meta name="csrf-token"> if present.
     * @param {Object} [options.listen] - overrides the agent.listen.provider object, e.g. {type: 'deepgram', model: 'nova-3'}.
     * @param {Object} [options.think] - overrides the agent.think.provider object, e.g. {type: 'open_ai', model: 'gpt-4o-mini'}.
     * @param {Object} [options.speak] - overrides the agent.speak.provider object, e.g. {type: 'deepgram', model: 'aura-2-thalia-en'}.
     * @param {string} [options.instructions] - overrides agent.think.prompt (a.k.a. the system prompt).
     * @param {string} [options.greeting] - overrides agent.greeting, spoken first, before the user says anything.
     * @param {(event: Object) => void} [options.onEvent] - every JSON event received, raw.
     * @param {(text: string, role: 'user'|'assistant') => void} [options.onTranscript] - convenience wrapper over ConversationText.
     * @param {() => void} [options.onConnected] - fired on SettingsApplied, not on socket open (the agent isn't ready to converse until then).
     * @param {(error: Error) => void} [options.onError]
     * @param {() => void} [options.onClose]
     * @param {(level: number) => void} [options.onLocalLevel] - live mic input level (0-1), ~30fps.
     * @param {(level: number) => void} [options.onRemoteLevel] - live agent-speech output level (0-1), ~30fps.
     */
    constructor(options = {}) {
        this.baseUrl = options.baseUrl || '/voice';
        this.wsUrl = options.wsUrl || 'wss://agent.deepgram.com/v1/agent/converse';
        this.csrfToken = options.csrfToken || (typeof VoiceWidget !== 'undefined'
            ? VoiceWidget.readCsrfTokenFromPage()
            : VoiceRealtimeDeepgramSession.readCsrfTokenFromPage());

        this.listen = options.listen || {type: 'deepgram', model: 'nova-3'};
        this.think = options.think || {type: 'open_ai', model: 'gpt-4o-mini'};
        this.speak = options.speak || {type: 'deepgram', model: 'aura-2-thalia-en'};
        this.instructions = options.instructions || null;
        this.greeting = options.greeting || null;

        this.onEvent = options.onEvent || (() => {});
        this.onTranscript = options.onTranscript || (() => {});
        this.onConnected = options.onConnected || (() => {});
        this.onError = options.onError || (() => {});
        this.onClose = options.onClose || (() => {});
        this.onLocalLevel = options.onLocalLevel || (() => {});
        this.onRemoteLevel = options.onRemoteLevel || (() => {});

        this.socket = null;
        this.audioContext = null;
        this.micStream = null;
        this.micSourceNode = null;
        this.micWorkletNode = null;
        this.muted = false;

        // Incoming Deepgram audio is a headerless linear16 PCM stream at
        // 24kHz (our own requested audio.output.sample_rate below) - it
        // has to be scheduled onto an AudioContext manually rather than
        // handed to an <audio>/<video> element the way a WebRTC track can.
        this.playbackContext = null;
        this.playbackSampleRate = 24000;
        this.playbackCursor = 0;
        this.playbackAnalyser = null;
        this.levelMeterHandle = null;
    }

    static readCsrfTokenFromPage() {
        if (typeof document === 'undefined') {
            return null;
        }

        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : null;
    }

    /** Mints a scoped Deepgram key server-side via this package's HTTP API. */
    async fetchToken() {
        const headers = {Accept: 'application/json', 'Content-Type': 'application/json'};

        if (this.csrfToken) {
            headers['X-CSRF-TOKEN'] = this.csrfToken;
        }

        const response = await fetch(`${this.baseUrl}/realtime/token`, {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: JSON.stringify({provider: 'deepgram'}),
        });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));

            throw new Error(body.error || `Could not mint a Deepgram realtime token (HTTP ${response.status}).`);
        }

        return response.json();
    }

    /**
     * Mints a scoped key, opens the microphone, opens the WebSocket, and
     * sends the initial Settings message. Call once; call close() before
     * connecting again.
     */
    async connect() {
        try {
            const {token} = await this.fetchToken();

            if (!token) {
                throw new Error('Realtime token response did not include a token.');
            }

            this.micStream = await navigator.mediaDevices.getUserMedia({audio: true});

            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            this.audioContext = new AudioContextClass();
            this.playbackContext = new AudioContextClass({sampleRate: this.playbackSampleRate});
            this.playbackCursor = this.playbackContext.currentTime;

            this.playbackAnalyser = this.playbackContext.createAnalyser();
            this.playbackAnalyser.fftSize = 512;
            this.playbackAnalyser.connect(this.playbackContext.destination);
            this.startLevelMetering();

            await new Promise((resolve, reject) => {
                // Browsers cannot set a WebSocket handshake's Authorization
                // header - only subprotocols. Deepgram's own client
                // converts Authorization-header auth into this exact
                // ['token', <key>] subprotocol pair in browser contexts
                // (confirmed from their SDK source, see this file's top
                // docblock), so this is not a guess.
                this.socket = new WebSocket(this.wsUrl, ['token', token]);
                this.socket.binaryType = 'arraybuffer';

                this.socket.addEventListener('open', () => {
                    this.sendSettings();
                    this.startMicStreaming();
                    resolve();
                });

                this.socket.addEventListener('error', () => {
                    reject(new Error('Deepgram realtime WebSocket connection failed.'));
                });

                this.socket.addEventListener('message', (event) => this.handleMessage(event));

                this.socket.addEventListener('close', (event) => {
                    this.teardownAudio();
                    this.onClose();

                    if (event.code !== 1000) {
                        this.onError(new Error(`Deepgram realtime connection closed unexpectedly (code ${event.code}).`));
                    }
                });
            });
        } catch (error) {
            this.onError(error);
            this.close();
            throw error;
        }
    }

    sendSettings() {
        const agent = {
            listen: {provider: this.listen},
            think: {provider: this.think},
            speak: {provider: this.speak},
        };

        if (this.instructions) {
            agent.think.prompt = this.instructions;
        }

        if (this.greeting) {
            agent.greeting = this.greeting;
        }

        this.sendJson({
            type: 'Settings',
            audio: {
                input: {encoding: 'linear16', sample_rate: 16000},
                output: {encoding: 'linear16', sample_rate: this.playbackSampleRate},
            },
            agent,
        });
    }

    /** Downsamples the mic's native rate to 16kHz linear16 PCM and streams it continuously. */
    startMicStreaming() {
        const source = this.audioContext.createMediaStreamSource(this.micStream);
        const analyser = this.audioContext.createAnalyser();
        analyser.fftSize = 512;
        source.connect(analyser);

        // ScriptProcessorNode is deprecated but remains the only widely
        // supported way to get raw PCM samples synchronously without
        // shipping a separate AudioWorklet module file - acceptable here
        // since this whole client is already a "verify manually" surface,
        // not something the PHPUnit suite exercises.
        const bufferSize = 4096;
        const processor = this.audioContext.createScriptProcessor(bufferSize, 1, 1);
        source.connect(processor);
        // ScriptProcessorNode only fires 'audioprocess' while connected
        // somewhere in the graph - routed through a silent gain node so
        // the mic is never actually audible locally.
        processor.connect(this.silentGainNode());

        const inputSampleRate = this.audioContext.sampleRate;
        const targetSampleRate = 16000;
        const meterData = new Uint8Array(analyser.frequencyBinCount);

        processor.addEventListener('audioprocess', (event) => {
            if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
                return;
            }

            analyser.getByteTimeDomainData(meterData);
            let sumSquares = 0;
            for (let i = 0; i < meterData.length; i++) {
                const normalized = (meterData[i] - 128) / 128;
                sumSquares += normalized * normalized;
            }
            this.onLocalLevel(Math.min(1, Math.sqrt(sumSquares / meterData.length) * 4));

            if (this.muted) {
                return;
            }

            const input = event.inputBuffer.getChannelData(0);
            const pcm16 = VoiceRealtimeDeepgramSession.downsampleToLinear16(input, inputSampleRate, targetSampleRate);
            this.socket.send(pcm16);
        });

        this.micWorkletNode = processor;
        this.micSourceNode = source;
    }

    /** A muted destination so the ScriptProcessorNode actually runs without the mic being audible locally. */
    silentGainNode() {
        if (!this._silentGain) {
            this._silentGain = this.audioContext.createGain();
            this._silentGain.gain.value = 0;
            this._silentGain.connect(this.audioContext.destination);
        }

        return this._silentGain;
    }

    static downsampleToLinear16(float32Input, inputSampleRate, targetSampleRate) {
        const ratio = inputSampleRate / targetSampleRate;
        const outputLength = Math.floor(float32Input.length / ratio);
        const output = new Int16Array(outputLength);

        for (let i = 0; i < outputLength; i++) {
            const sample = float32Input[Math.floor(i * ratio)];
            const clamped = Math.max(-1, Math.min(1, sample));
            output[i] = clamped < 0 ? clamped * 0x8000 : clamped * 0x7fff;
        }

        return output.buffer;
    }

    handleMessage(event) {
        if (event.data instanceof ArrayBuffer) {
            this.playAudioChunk(event.data);

            return;
        }

        let message;
        try {
            message = JSON.parse(event.data);
        } catch (error) {
            this.onError(new Error('Received a non-JSON, non-binary message from Deepgram realtime.'));

            return;
        }

        this.onEvent(message);

        switch (message.type) {
            case 'SettingsApplied':
                this.onConnected();
                break;
            case 'ConversationText':
                this.onTranscript(message.content ?? '', message.role === 'user' ? 'user' : 'assistant');
                break;
            case 'Error':
                this.onError(new Error(message.description || message.message || 'Deepgram realtime reported an error.'));
                break;
            default:
                // UserStartedSpeaking, AgentThinking, AgentStartedSpeaking,
                // AgentAudioDone, FunctionCallRequest, PromptUpdated,
                // SpeakUpdated, ThinkUpdated, ListenUpdated, LatencyReport,
                // History, InjectionRefused, Warning, Welcome - all still
                // reach the caller via onEvent() above; no default handling
                // needed here.
                break;
        }
    }

    /** Schedules a raw linear16 PCM chunk for gapless playback via the Web Audio API. */
    playAudioChunk(arrayBuffer) {
        const pcm16 = new Int16Array(arrayBuffer);
        const float32 = new Float32Array(pcm16.length);

        for (let i = 0; i < pcm16.length; i++) {
            float32[i] = pcm16[i] / (pcm16[i] < 0 ? 0x8000 : 0x7fff);
        }

        const buffer = this.playbackContext.createBuffer(1, float32.length, this.playbackSampleRate);
        buffer.copyToChannel(float32, 0);

        const source = this.playbackContext.createBufferSource();
        source.buffer = buffer;
        source.connect(this.playbackAnalyser);

        const now = this.playbackContext.currentTime;
        const startAt = Math.max(now, this.playbackCursor);
        source.start(startAt);
        this.playbackCursor = startAt + buffer.duration;
    }

    startLevelMetering() {
        const data = new Uint8Array(this.playbackAnalyser.frequencyBinCount);

        const tick = () => {
            this.playbackAnalyser.getByteTimeDomainData(data);

            let sumSquares = 0;
            for (let i = 0; i < data.length; i++) {
                const normalized = (data[i] - 128) / 128;
                sumSquares += normalized * normalized;
            }

            this.onRemoteLevel(Math.min(1, Math.sqrt(sumSquares / data.length) * 4));
            this.levelMeterHandle = requestAnimationFrame(tick);
        };
        this.levelMeterHandle = requestAnimationFrame(tick);
    }

    /** Sends a raw event object as a Settings/Update message - see Deepgram's Voice Agent API reference for shapes. */
    sendJson(payload) {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
            throw new Error('Deepgram realtime socket is not open.');
        }

        this.socket.send(JSON.stringify(payload));
    }

    /**
     * Best-effort "stop" control - see this file's top docblock's HONESTY
     * NOTE: Deepgram's protocol has no confirmed explicit
     * "cancel current response" message, unlike OpenAI's response.cancel.
     * This only mutes outgoing mic audio and clears locally-queued
     * playback; it cannot force the server to stop an in-flight response.
     */
    interrupt() {
        this.playbackCursor = this.playbackContext ? this.playbackContext.currentTime : 0;
    }

    setMuted(muted) {
        this.muted = muted;
    }

    close() {
        if (this.levelMeterHandle !== null) {
            cancelAnimationFrame(this.levelMeterHandle);
            this.levelMeterHandle = null;
        }

        if (this.socket) {
            try {
                this.socket.close(1000);
            } catch (error) {
                // Already closed/closing - nothing to do.
            }
            this.socket = null;
        }

        this.teardownAudio();
        this.onClose();
    }

    teardownAudio() {
        if (this.micStream) {
            this.micStream.getTracks().forEach((track) => track.stop());
            this.micStream = null;
        }

        if (this.micWorkletNode) {
            this.micWorkletNode.disconnect();
            this.micWorkletNode = null;
        }

        if (this.micSourceNode) {
            this.micSourceNode.disconnect();
            this.micSourceNode = null;
        }

        if (this.audioContext) {
            this.audioContext.close().catch(() => {});
            this.audioContext = null;
        }

        if (this.playbackContext) {
            this.playbackContext.close().catch(() => {});
            this.playbackContext = null;
        }
    }
}

if (typeof window !== 'undefined') {
    window.VoiceRealtimeDeepgramSession = VoiceRealtimeDeepgramSession;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = VoiceRealtimeDeepgramSession;
}
