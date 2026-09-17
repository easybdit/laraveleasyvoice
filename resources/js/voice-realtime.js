/**
 * LaravelEasyVoice realtime client - opens a live WebRTC voice connection
 * directly from the browser to OpenAI's Realtime API. This file is
 * separate from voice-widget.js on purpose: it's a genuinely different
 * concern (a live duplex connection vs. one-turn-at-a-time HTTP calls),
 * optional, and OpenAI-specific.
 *
 * Laravel's only involvement is minting the ephemeral token via
 * POST {baseUrl}/realtime/token (see Contracts/RealtimeVoiceProvider's
 * docblock and this package's README for why nothing server-side
 * mediates the actual audio - PHP cannot hold a WebRTC connection open).
 * Everything below runs entirely in the browser once the token is minted.
 *
 * Every field/endpoint/event name here was verified against OpenAI's own
 * current API documentation before writing this file, not guessed:
 *   - POST https://api.openai.com/v1/realtime/calls (SDP offer/answer
 *     exchange), Authorization: Bearer <ephemeral token>,
 *     Content-Type: application/sdp, raw SDP as the request body, raw
 *     SDP answer as the response body.
 *   - A WebRTC data channel labelled "oai-events" carries JSON events
 *     both ways (session.update, conversation.item.create, etc.).
 *   - {"type": "response.cancel"} cancels a response in progress.
 *
 * HONESTY NOTE, deliberately kept here rather than only in docs: OpenAI's
 * server-side voice-activity detection already auto-interrupts a
 * response when it detects the user speaking, in the default session
 * configuration - interrupt() below is for an explicit "stop" control on
 * top of that, not the only thing making barge-in work. Community
 * reports (linked in this package's README) describe occasional
 * reliability issues with response.cancel/conversation.item.truncate on
 * OpenAI's side; this client cannot work around that, only surface
 * whatever OpenAI's own connection reports back via onEvent()/onError().
 *
 * This file cannot be exercised by this package's PHPUnit suite - there
 * is no way to open a real WebRTC connection or use real microphone/
 * speaker hardware from a PHP test process. Verify manually, in a real
 * browser, against a real OpenAI Realtime-enabled account.
 */
class VoiceRealtimeSession {
    /**
     * @param {Object} options
     * @param {string} [options.baseUrl='/voice'] - matches config('voice.routes.prefix').
     * @param {string} [options.csrfToken] - defaults to <meta name="csrf-token"> if present.
     * @param {string} [options.model] - overrides config('voice.realtime.openai.model') for this call.
     * @param {string} [options.voice] - overrides config('voice.realtime.openai.voice') for this call.
     * @param {(event: Object) => void} [options.onEvent] - every JSON event received on the data channel, raw.
     * @param {() => void} [options.onConnected]
     * @param {(error: Error) => void} [options.onError]
     * @param {() => void} [options.onClose]
     * @param {(level: number) => void} [options.onRemoteLevel] - live level (0-1) of the model's voice, ~30fps.
     */
    constructor(options = {}) {
        this.baseUrl = options.baseUrl || '/voice';
        this.model = options.model || null;
        this.voice = options.voice || null;
        this.csrfToken = options.csrfToken || (typeof VoiceWidget !== 'undefined'
            ? VoiceWidget.readCsrfTokenFromPage()
            : VoiceRealtimeSession.readCsrfTokenFromPage());

        this.onEvent = options.onEvent || (() => {});
        this.onConnected = options.onConnected || (() => {});
        this.onError = options.onError || (() => {});
        this.onClose = options.onClose || (() => {});
        this.onRemoteLevel = options.onRemoteLevel || (() => {});

        this.peerConnection = null;
        this.dataChannel = null;
        this.localStream = null;
        this.remoteAudioEl = null;
        this.audioContext = null;
        this.levelMeterHandle = null;
    }

    static readCsrfTokenFromPage() {
        if (typeof document === 'undefined') {
            return null;
        }

        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : null;
    }

    /** Mints an ephemeral token server-side via this package's HTTP API. */
    async fetchToken() {
        const headers = {Accept: 'application/json', 'Content-Type': 'application/json'};

        if (this.csrfToken) {
            headers['X-CSRF-TOKEN'] = this.csrfToken;
        }

        const response = await fetch(`${this.baseUrl}/realtime/token`, {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: JSON.stringify({model: this.model, voice: this.voice}),
        });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));

            throw new Error(body.error || `Could not mint a realtime token (HTTP ${response.status}).`);
        }

        return response.json();
    }

    /**
     * Mints a token, opens the microphone, negotiates the WebRTC
     * connection with OpenAI directly, and starts streaming. Call once;
     * call close() before connecting again.
     */
    async connect() {
        try {
            const {token} = await this.fetchToken();

            if (!token) {
                throw new Error('Realtime token response did not include a token.');
            }

            this.localStream = await navigator.mediaDevices.getUserMedia({audio: true});

            this.peerConnection = new RTCPeerConnection();
            this.localStream.getTracks().forEach((track) => this.peerConnection.addTrack(track, this.localStream));

            this.peerConnection.addEventListener('track', (event) => this.attachRemoteTrack(event.streams[0]));

            this.dataChannel = this.peerConnection.createDataChannel('oai-events');
            this.dataChannel.addEventListener('message', (event) => {
                try {
                    this.onEvent(JSON.parse(event.data));
                } catch (error) {
                    // A non-JSON message on this channel would be unexpected -
                    // surfaced via onError rather than silently swallowed.
                    this.onError(error);
                }
            });

            const offer = await this.peerConnection.createOffer();
            await this.peerConnection.setLocalDescription(offer);

            const sdpResponse = await fetch('https://api.openai.com/v1/realtime/calls', {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${token}`,
                    'Content-Type': 'application/sdp',
                },
                body: offer.sdp,
            });

            if (!sdpResponse.ok) {
                throw new Error(`OpenAI realtime connection failed (HTTP ${sdpResponse.status}).`);
            }

            const answerSdp = await sdpResponse.text();
            await this.peerConnection.setRemoteDescription({type: 'answer', sdp: answerSdp});

            this.onConnected();
        } catch (error) {
            this.onError(error);
            this.close();
            throw error;
        }
    }

    attachRemoteTrack(stream) {
        this.remoteAudioEl = new Audio();
        this.remoteAudioEl.srcObject = stream;
        this.remoteAudioEl.autoplay = true;

        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            this.audioContext = new AudioContextClass();

            const source = this.audioContext.createMediaStreamSource(stream);
            const analyser = this.audioContext.createAnalyser();
            analyser.fftSize = 512;
            analyser.smoothingTimeConstant = 0.75;
            source.connect(analyser);

            const data = new Uint8Array(analyser.frequencyBinCount);
            const tick = () => {
                analyser.getByteTimeDomainData(data);

                let sumSquares = 0;
                for (let i = 0; i < data.length; i++) {
                    const normalized = (data[i] - 128) / 128;
                    sumSquares += normalized * normalized;
                }

                this.onRemoteLevel(Math.min(1, Math.sqrt(sumSquares / data.length) * 4));
                this.levelMeterHandle = requestAnimationFrame(tick);
            };
            this.levelMeterHandle = requestAnimationFrame(tick);
        } catch (error) {
            // Metering is a visual nicety only - never let it block audio.
        }
    }

    /** Sends a raw event object over the data channel - see OpenAI's Realtime API event reference for shapes. */
    send(event) {
        if (!this.dataChannel || this.dataChannel.readyState !== 'open') {
            throw new Error('Realtime data channel is not open.');
        }

        this.dataChannel.send(JSON.stringify(event));
    }

    /**
     * Explicit "stop" control - sends {"type": "response.cancel"}. See
     * this file's own top docblock: server-side VAD already
     * auto-interrupts in the default session config, so this is a
     * backstop/manual control, not the only mechanism.
     */
    interrupt() {
        this.send({type: 'response.cancel'});
    }

    close() {
        if (this.levelMeterHandle !== null) {
            cancelAnimationFrame(this.levelMeterHandle);
            this.levelMeterHandle = null;
        }

        if (this.localStream) {
            this.localStream.getTracks().forEach((track) => track.stop());
            this.localStream = null;
        }

        if (this.dataChannel) {
            this.dataChannel.close();
            this.dataChannel = null;
        }

        if (this.peerConnection) {
            this.peerConnection.close();
            this.peerConnection = null;
        }

        this.remoteAudioEl = null;
        this.onClose();
    }
}

if (typeof window !== 'undefined') {
    window.VoiceRealtimeSession = VoiceRealtimeSession;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = VoiceRealtimeSession;
}
