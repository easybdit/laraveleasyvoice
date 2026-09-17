/**
 * LaravelEasyVoice browser widget - a small, dependency-free client for
 * this package's opt-in HTTP API (POST /voice/sessions, .../turns,
 * GET .../turns/{turn}/audio). Talks only to endpoints this package
 * already tests server-side; ships no third-party code.
 *
 * Usage:
 *   const widget = new VoiceWidget({ agent: 'receptionist' });
 *   await widget.start();
 *   await widget.startRecording();      // mic permission prompt happens here
 *   const turn = await widget.stopRecording();  // uploads, plays the reply
 *   widget.stopSpeaking();              // client-side "stop talking" (see note below)
 *   await widget.end();
 *
 * NOT real-time barge-in: this widget drives the turn-based HTTP API, so
 * by the time audio is playing, the assistant's reply was already fully
 * generated. stopSpeaking() only stops local playback. True mid-generation
 * cancellation needs a realtime connection, which this package does not
 * implement (see Contracts/RealtimeVoiceProvider's docblock).
 */
class VoiceWidget {
    /**
     * @param {Object} options
     * @param {string} options.agent - the registered agent name to start a session for.
     * @param {string} [options.baseUrl='/voice'] - matches config('voice.routes.prefix').
     * @param {string} [options.csrfToken] - defaults to <meta name="csrf-token"> if present.
     * @param {string} [options.language] - passed through as the session's language.
     * @param {(state: string) => void} [options.onStateChange]
     * @param {(transcript: string) => void} [options.onTranscript] - the caller's own transcribed speech.
     * @param {(turn: Object) => void} [options.onResponse] - the full turn response (transcript, audio_url, tool_calls).
     * @param {(error: Error) => void} [options.onError]
     */
    constructor(options = {}) {
        if (!options.agent) {
            throw new Error('VoiceWidget requires an "agent" option.');
        }

        this.agent = options.agent;
        this.baseUrl = options.baseUrl || '/voice';
        this.language = options.language || null;
        this.csrfToken = options.csrfToken || VoiceWidget.readCsrfTokenFromPage();

        this.onStateChange = options.onStateChange || (() => {});
        this.onTranscript = options.onTranscript || (() => {});
        this.onResponse = options.onResponse || (() => {});
        this.onError = options.onError || (() => {});

        this.session = null;
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.currentAudio = null;
        this.state = 'idle';
    }

    static readCsrfTokenFromPage() {
        if (typeof document === 'undefined') {
            return null;
        }

        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : null;
    }

    setState(state) {
        this.state = state;
        this.onStateChange(state);
    }

    buildHeaders(extra = {}) {
        const headers = Object.assign({Accept: 'application/json'}, extra);

        if (this.csrfToken) {
            headers['X-CSRF-TOKEN'] = this.csrfToken;
        }

        return headers;
    }

    /** Starts a voice session. Must be called before recording. */
    async start() {
        this.setState('starting');

        try {
            const response = await fetch(`${this.baseUrl}/sessions`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: this.buildHeaders({'Content-Type': 'application/json'}),
                body: JSON.stringify({agent: this.agent, language: this.language}),
            });

            if (!response.ok) {
                throw new Error(`Could not start a voice session (HTTP ${response.status}).`);
            }

            this.session = await response.json();
            this.setState('ready');

            return this.session;
        } catch (error) {
            this.setState('idle');
            this.onError(error);
            throw error;
        }
    }

    /** Requests microphone access (prompts the user) and starts recording. */
    async startRecording() {
        if (!this.session) {
            throw new Error('Call start() before startRecording().');
        }

        const stream = await navigator.mediaDevices.getUserMedia({audio: true});

        this.audioChunks = [];
        this.mediaRecorder = new MediaRecorder(stream);
        this.mediaRecorder.addEventListener('dataavailable', (event) => {
            if (event.data && event.data.size > 0) {
                this.audioChunks.push(event.data);
            }
        });
        this.mediaRecorder.start();
        this.setState('recording');
    }

    /** Stops recording, uploads the turn, and plays the assistant's reply. */
    stopRecording() {
        return new Promise((resolve, reject) => {
            if (!this.mediaRecorder) {
                reject(new Error('startRecording() was never called.'));

                return;
            }

            this.mediaRecorder.addEventListener('stop', () => {
                this.mediaRecorder.stream.getTracks().forEach((track) => track.stop());

                const blob = new Blob(this.audioChunks, {type: 'audio/webm'});

                this.sendTurn(blob).then(resolve).catch(reject);
            }, {once: true});

            this.mediaRecorder.stop();
        });
    }

    /** Uploads a recorded audio blob as one turn - called by stopRecording(), or directly with your own audio. */
    async sendTurn(audioBlob) {
        this.setState('uploading');

        const formData = new FormData();
        formData.append('audio', audioBlob, 'turn.webm');

        try {
            const response = await fetch(`${this.baseUrl}/sessions/${this.session.id}/turns`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: this.buildHeaders(),
                body: formData,
            });

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(body.error || `Turn failed (HTTP ${response.status}).`);
            }

            this.onTranscript(body.transcript);
            this.onResponse(body);

            if (body.audio_url) {
                this.play(body.audio_url);
            } else {
                this.setState('ready');
            }

            return body;
        } catch (error) {
            this.setState('ready');
            this.onError(error);
            throw error;
        }
    }

    /** Plays a turn's response audio, replacing anything currently playing. */
    play(audioUrl) {
        this.stopSpeaking();

        this.currentAudio = new Audio(audioUrl);
        this.currentAudio.addEventListener('ended', () => this.setState('ready'));

        this.setState('speaking');

        this.currentAudio.play().catch((error) => {
            this.setState('ready');
            this.onError(error);
        });
    }

    /**
     * Stops local playback immediately - a "stop talking" control, not
     * server-side barge-in. See this file's own top-level docblock.
     */
    stopSpeaking() {
        if (this.currentAudio) {
            this.currentAudio.pause();
            this.currentAudio.currentTime = 0;
            this.currentAudio = null;
        }

        if (this.state === 'speaking') {
            this.setState('ready');
        }
    }

    /** Ends the session on the server and resets local state. */
    async end() {
        if (!this.session) {
            return;
        }

        this.stopSpeaking();

        const sessionId = this.session.id;
        this.session = null;

        try {
            await fetch(`${this.baseUrl}/sessions/${sessionId}/end`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: this.buildHeaders({'Content-Type': 'application/json'}),
                body: JSON.stringify({}),
            });
        } catch (error) {
            this.onError(error);
        } finally {
            this.setState('ended');
        }
    }
}

if (typeof window !== 'undefined') {
    window.VoiceWidget = VoiceWidget;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = VoiceWidget;
}
