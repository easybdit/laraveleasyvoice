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
     * @param {(level: number) => void} [options.onLevel] - live mic input level (0-1) while recording, ~30fps.
     * @param {(level: number) => void} [options.onPlaybackLevel] - live playback level (0-1) while speaking, ~30fps.
     * @param {(percent: number) => void} [options.onUploadProgress] - upload progress (0-100) while a turn uploads.
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
        this.onLevel = options.onLevel || (() => {});
        this.onPlaybackLevel = options.onPlaybackLevel || (() => {});
        this.onUploadProgress = options.onUploadProgress || (() => {});

        this.session = null;
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.currentAudio = null;
        this.state = 'idle';

        // Lazily created, reused for the lifetime of the widget - browsers
        // cap how many AudioContexts can be alive at once.
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

    getAudioContext() {
        if (!this.audioContext) {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            this.audioContext = new AudioContextClass();
        }

        if (this.audioContext.state === 'suspended') {
            this.audioContext.resume().catch(() => {});
        }

        return this.audioContext;
    }

    /**
     * Wires an AnalyserNode onto an audio source and reports its RMS level
     * (0-1) on every animation frame until stopLevelMeter() is called.
     * Used for both the mic input (recording) and the reply audio (speaking).
     */
    startLevelMeter(sourceNode, callback) {
        this.stopLevelMeter();

        const analyser = this.getAudioContext().createAnalyser();
        analyser.fftSize = 512;
        analyser.smoothingTimeConstant = 0.75;
        sourceNode.connect(analyser);

        const data = new Uint8Array(analyser.frequencyBinCount);

        const tick = () => {
            analyser.getByteTimeDomainData(data);

            let sumSquares = 0;
            for (let i = 0; i < data.length; i++) {
                const normalized = (data[i] - 128) / 128;
                sumSquares += normalized * normalized;
            }

            callback(Math.min(1, Math.sqrt(sumSquares / data.length) * 4));

            this.levelMeterHandle = requestAnimationFrame(tick);
        };

        this.levelMeterHandle = requestAnimationFrame(tick);

        return analyser;
    }

    stopLevelMeter() {
        if (this.levelMeterHandle !== null) {
            cancelAnimationFrame(this.levelMeterHandle);
            this.levelMeterHandle = null;
        }
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

        try {
            const micSource = this.getAudioContext().createMediaStreamSource(stream);
            this.startLevelMeter(micSource, this.onLevel);
        } catch (error) {
            // Metering is a visual nicety only - never let it block recording.
        }
    }

    /** Stops recording, uploads the turn, and plays the assistant's reply. */
    stopRecording() {
        return new Promise((resolve, reject) => {
            if (!this.mediaRecorder) {
                reject(new Error('startRecording() was never called.'));

                return;
            }

            this.stopLevelMeter();
            this.onLevel(0);

            this.mediaRecorder.addEventListener('stop', () => {
                this.mediaRecorder.stream.getTracks().forEach((track) => track.stop());

                const blob = new Blob(this.audioChunks, {type: 'audio/webm'});

                this.sendTurn(blob).then(resolve).catch(reject);
            }, {once: true});

            this.mediaRecorder.stop();
        });
    }

    /**
     * Uploads a recorded audio blob as one turn - called by stopRecording(),
     * or directly with your own audio. Uses XMLHttpRequest rather than
     * fetch() solely because fetch has no upload-progress event; everything
     * else about the request/response contract is identical.
     */
    sendTurn(audioBlob) {
        this.setState('uploading');
        this.onUploadProgress(0);

        const formData = new FormData();
        formData.append('audio', audioBlob, 'turn.webm');

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${this.baseUrl}/sessions/${this.session.id}/turns`);
            xhr.withCredentials = true;
            xhr.responseType = 'json';

            const headers = this.buildHeaders();
            Object.keys(headers).forEach((name) => xhr.setRequestHeader(name, headers[name]));

            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    this.onUploadProgress(Math.round((event.loaded / event.total) * 100));
                }
            });

            xhr.addEventListener('load', () => {
                const body = xhr.response || {};

                if (xhr.status < 200 || xhr.status >= 300) {
                    const error = new Error(body.error || `Turn failed (HTTP ${xhr.status}).`);
                    this.setState('ready');
                    this.onError(error);
                    reject(error);

                    return;
                }

                this.onUploadProgress(100);
                this.onTranscript(body.transcript);
                this.onResponse(body);

                if (body.audio_url) {
                    this.play(body.audio_url);
                } else {
                    this.setState('ready');
                }

                resolve(body);
            });

            xhr.addEventListener('error', () => {
                const error = new Error('Turn upload failed - check your connection.');
                this.setState('ready');
                this.onError(error);
                reject(error);
            });

            xhr.send(formData);
        });
    }

    /** Plays a turn's response audio, replacing anything currently playing. */
    play(audioUrl) {
        this.stopSpeaking();

        this.currentAudio = new Audio(audioUrl);
        this.currentAudio.crossOrigin = 'anonymous';
        this.currentAudio.addEventListener('ended', () => this.setState('ready'));

        this.setState('speaking');

        try {
            const playbackSource = this.getAudioContext().createMediaElementSource(this.currentAudio);
            playbackSource.connect(this.getAudioContext().destination);
            this.startLevelMeter(playbackSource, this.onPlaybackLevel);
        } catch (error) {
            // Metering is a visual nicety only - never let it block playback.
        }

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

        this.stopLevelMeter();
        this.onPlaybackLevel(0);

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
