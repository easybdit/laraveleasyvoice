<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LaravelEasyVoice example</title>
    <style>
        :root {
            --bg-1: #0b0f1f;
            --bg-2: #171130;
            --card: rgba(255, 255, 255, 0.05);
            --card-border: rgba(255, 255, 255, 0.09);
            --text: #eef0fb;
            --muted: #9498b8;
            --accent: #7c6cf0;
            --accent-2: #4fd1c5;
            --danger: #f0617c;
            --warn: #f2b53c;
            --ok: #4fd18b;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--text);
            background:
                radial-gradient(60% 50% at 15% 10%, rgba(124, 108, 240, 0.25), transparent 60%),
                radial-gradient(55% 45% at 90% 15%, rgba(79, 209, 197, 0.18), transparent 60%),
                linear-gradient(160deg, var(--bg-1), var(--bg-2));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            width: 100%;
            max-width: 460px;
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 32px 28px 28px;
            backdrop-filter: blur(18px);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.45);
        }

        .header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 4px;
        }

        .header .logo {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .header h1 {
            font-size: 17px;
            margin: 0;
            font-weight: 600;
            letter-spacing: 0.2px;
        }

        .header p {
            margin: 1px 0 0;
            font-size: 12.5px;
            color: var(--muted);
        }

        .status-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 26px 0 10px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 600;
            letter-spacing: 0.2px;
            background: rgba(124, 108, 240, 0.14);
            color: #c9c3fb;
            transition: background 0.25s ease, color 0.25s ease;
        }

        .status-pill .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-pill.state-recording { background: rgba(240, 97, 124, 0.16); color: #ffb0c0; }
        .status-pill.state-recording .dot { animation: blink 1s ease-in-out infinite; }
        .status-pill.state-uploading, .status-pill.state-thinking { background: rgba(242, 181, 60, 0.16); color: #ffd489; }
        .status-pill.state-speaking { background: rgba(79, 209, 139, 0.16); color: #9df0c1; }
        .status-pill.state-ready { background: rgba(79, 209, 197, 0.14); color: #a8ece5; }

        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }

        .stage {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 14px 0 6px;
        }

        .mic-wrap {
            position: relative;
            width: 168px;
            height: 168px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ring {
            position: absolute;
            border-radius: 50%;
            border: 1.5px solid var(--accent);
            opacity: 0;
            transform: scale(0.7);
            pointer-events: none;
        }

        .mic-btn {
            position: relative;
            z-index: 2;
            width: 108px;
            height: 108px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            background: linear-gradient(150deg, var(--accent), #5847c9);
            box-shadow: 0 10px 30px rgba(124, 108, 240, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s ease, box-shadow 0.25s ease, background 0.25s ease;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }

        .mic-btn:disabled { cursor: not-allowed; filter: saturate(0.4); opacity: 0.55; }
        .mic-btn:active:not(:disabled) { transform: scale(0.96); }

        .mic-btn.is-recording {
            background: linear-gradient(150deg, var(--danger), #b6395a);
            box-shadow: 0 10px 34px rgba(240, 97, 124, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.25);
        }

        .mic-btn.is-busy {
            background: linear-gradient(150deg, var(--warn), #b9821e);
        }

        .mic-btn.is-speaking {
            background: linear-gradient(150deg, var(--ok), #2f9e63);
        }

        .mic-btn svg { width: 38px; height: 38px; stroke: white; }

        .spinner {
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            border: 3px solid transparent;
            border-top-color: rgba(255, 255, 255, 0.85);
            opacity: 0;
            animation: spin 0.9s linear infinite;
        }
        .spinner.on { opacity: 1; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .hint {
            margin-top: 14px;
            font-size: 12.5px;
            color: var(--muted);
            text-align: center;
            min-height: 16px;
        }

        .bars {
            display: flex;
            align-items: flex-end;
            gap: 4px;
            height: 30px;
            margin-top: 16px;
        }
        .bars span {
            width: 4px;
            border-radius: 3px;
            background: linear-gradient(180deg, var(--accent-2), var(--accent));
            height: 4px;
            transition: height 0.08s ease;
        }

        .progress-track {
            width: 100%;
            height: 4px;
            border-radius: 3px;
            background: rgba(255, 255, 255, 0.08);
            margin-top: 18px;
            overflow: hidden;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .progress-track.on { opacity: 1; }
        .progress-fill {
            height: 100%;
            width: 0%;
            border-radius: 3px;
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            transition: width 0.15s ease;
        }
        .progress-fill.indeterminate {
            width: 40% !important;
            animation: indeterminate 1.1s ease-in-out infinite;
        }
        @keyframes indeterminate {
            0% { transform: translateX(-120%); }
            100% { transform: translateX(280%); }
        }

        .transcript {
            margin-top: 22px;
            max-height: 260px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-right: 2px;
        }
        .transcript:empty { display: none; }
        .transcript::-webkit-scrollbar { width: 6px; }
        .transcript::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 3px; }

        .bubble {
            max-width: 84%;
            padding: 10px 13px;
            border-radius: 14px;
            font-size: 13.5px;
            line-height: 1.45;
            animation: rise 0.25s ease;
        }
        @keyframes rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        .bubble.user {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--accent), #5847c9);
            border-bottom-right-radius: 4px;
        }
        .bubble.assistant {
            align-self: flex-start;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom-left-radius: 4px;
        }
        .bubble .role {
            display: block;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            opacity: 0.6;
            margin-bottom: 3px;
        }

        .toast {
            position: fixed;
            top: 18px;
            left: 50%;
            transform: translateX(-50%) translateY(-10px);
            background: #2a1620;
            border: 1px solid rgba(240, 97, 124, 0.4);
            color: #ffc3d0;
            padding: 11px 18px;
            border-radius: 12px;
            font-size: 13px;
            max-width: 90vw;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4);
            z-index: 10;
        }
        .toast.on {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .stop-speaking {
            margin-top: 14px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text);
            font-size: 12.5px;
            padding: 7px 16px;
            border-radius: 999px;
            cursor: pointer;
            display: none;
            align-items: center;
            gap: 6px;
        }
        .stop-speaking.on { display: inline-flex; }
        .stop-speaking:hover { background: rgba(255, 255, 255, 0.09); }

        .brand-footer {
            margin-top: 26px;
            padding-top: 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.07);
            text-align: center;
            font-size: 11.5px;
            color: var(--muted);
            letter-spacing: 0.2px;
        }
        .brand-footer strong { color: #c9c3fb; font-weight: 600; }
        .brand-footer a {
            color: var(--accent-2);
            text-decoration: none;
            font-weight: 600;
        }
        .brand-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="logo">🎙️</div>
            <div>
                <h1>LaravelEasyVoice</h1>
                <p>Turn-based voice widget &middot; agent: <strong>receptionist</strong></p>
            </div>
        </div>

        <div class="status-row">
            <span class="status-pill" id="status-pill" data-state="idle">
                <span class="dot"></span>
                <span id="status-text">Connecting…</span>
            </span>
        </div>

        <div class="stage">
            <div class="mic-wrap">
                <div class="ring" id="ring-1"></div>
                <div class="ring" id="ring-2"></div>
                <div class="spinner" id="mic-spinner"></div>
                <button id="talk" class="mic-btn" disabled title="Hold to talk">
                    <svg id="mic-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                        <line x1="12" y1="19" x2="12" y2="23"></line>
                        <line x1="8" y1="23" x2="16" y2="23"></line>
                    </svg>
                </button>
            </div>

            <div class="hint" id="hint">Requesting a session…</div>

            <div class="bars" id="bars">
                <span></span><span></span><span></span><span></span><span></span><span></span><span></span>
            </div>

            <div class="progress-track" id="progress-track">
                <div class="progress-fill" id="progress-fill"></div>
            </div>

            <button class="stop-speaking" id="stop-speaking">■ Stop speaking</button>
        </div>

        <div class="transcript" id="transcript"></div>

        <div class="brand-footer">
            Built with <strong>LaravelEasyVoice</strong> &middot;
            <a href="https://github.com/easybdit/laraveleasyvoice" target="_blank" rel="noopener">github.com/easybdit/laraveleasyvoice</a>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script src="/vendor/laraveleasyvoice/voice-widget.js"></script>
    <script>
        const pill = document.getElementById('status-pill');
        const statusText = document.getElementById('status-text');
        const hint = document.getElementById('hint');
        const talkBtn = document.getElementById('talk');
        const micIcon = document.getElementById('mic-icon');
        const micSpinner = document.getElementById('mic-spinner');
        const ring1 = document.getElementById('ring-1');
        const ring2 = document.getElementById('ring-2');
        const barsEl = [...document.querySelectorAll('#bars span')];
        const progressTrack = document.getElementById('progress-track');
        const progressFill = document.getElementById('progress-fill');
        const transcriptEl = document.getElementById('transcript');
        const toastEl = document.getElementById('toast');
        const stopSpeakingBtn = document.getElementById('stop-speaking');

        const STATE_LABELS = {
            idle: 'Connecting…',
            starting: 'Connecting…',
            ready: 'Ready — hold to talk',
            recording: 'Listening…',
            uploading: 'Uploading…',
            thinking: 'Thinking…',
            speaking: 'Speaking…',
            ended: 'Session ended',
        };

        const HINTS = {
            idle: 'Requesting a session…',
            starting: 'Requesting a session…',
            ready: 'Press and hold the button, then speak.',
            recording: 'Release to send.',
            uploading: 'Sending your recording…',
            thinking: 'Transcribing and generating a reply…',
            speaking: 'Playing the reply.',
            ended: 'Session ended. Refresh to start a new one.',
        };

        let toastTimer = null;

        function showToast(message) {
            toastEl.textContent = message;
            toastEl.classList.add('on');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toastEl.classList.remove('on'), 4500);
        }

        function appendBubble(role, text) {
            const bubble = document.createElement('div');
            bubble.className = `bubble ${role}`;
            bubble.innerHTML = `<span class="role">${role === 'user' ? 'You' : 'Assistant'}</span>`;
            bubble.append(document.createTextNode(text || '…'));
            transcriptEl.appendChild(bubble);
            transcriptEl.scrollTop = transcriptEl.scrollHeight;
        }

        // Each bar chases the current level with its own phase/noise so a
        // single scalar level still reads as a live signal.
        const barState = barsEl.map((el, i) => ({el, phase: Math.random() * Math.PI * 2, speed: 0.15 + Math.random() * 0.1}));
        let currentLevel = 0;
        let barsRunning = false;

        function animateBars() {
            if (!barsRunning) return;

            barState.forEach((b) => {
                b.phase += b.speed;
                const wobble = 0.55 + 0.45 * Math.sin(b.phase);
                const height = Math.max(4, currentLevel * 26 * wobble + (currentLevel > 0.02 ? 2 : 0));
                b.el.style.height = `${height}px`;
            });

            requestAnimationFrame(animateBars);
        }

        function setBarsActive(active) {
            barsRunning = active;
            if (active) requestAnimationFrame(animateBars);
            else barState.forEach((b) => { b.el.style.height = '4px'; });
        }

        function setRingIntensity(level) {
            const scale = 1 + level * 0.55;
            const opacity = Math.min(0.55, 0.12 + level * 0.6);
            [ring1, ring2].forEach((r, i) => {
                r.style.transform = `scale(${scale + i * 0.18})`;
                r.style.opacity = opacity;
            });
        }

        function applyState(state) {
            pill.dataset.state = state;
            pill.className = `status-pill state-${state}`;
            statusText.textContent = STATE_LABELS[state] || state;
            hint.textContent = HINTS[state] || '';

            micIcon.style.display = 'block';
            micSpinner.classList.remove('on');
            talkBtn.classList.remove('is-recording', 'is-busy', 'is-speaking');
            talkBtn.disabled = !['ready', 'recording', 'speaking'].includes(state);
            stopSpeakingBtn.classList.remove('on');
            progressTrack.classList.remove('on');
            progressFill.classList.remove('indeterminate');

            if (state === 'recording') {
                talkBtn.classList.add('is-recording');
                setBarsActive(true);
            } else if (state === 'uploading' || state === 'thinking') {
                talkBtn.classList.add('is-busy');
                micIcon.style.display = 'none';
                micSpinner.classList.add('on');
                progressTrack.classList.add('on');
                setBarsActive(false);
                setRingIntensity(0);
                if (state === 'thinking') progressFill.classList.add('indeterminate');
            } else if (state === 'speaking') {
                talkBtn.classList.add('is-speaking');
                stopSpeakingBtn.classList.add('on');
                setBarsActive(true);
            } else {
                setBarsActive(false);
                setRingIntensity(0);
            }
        }

        // Matches the "Quickstart" agent name registered in
        // AppServiceProvider - see examples/README.md.
        const widget = new VoiceWidget({
            agent: 'receptionist',

            onStateChange: (state) => applyState(state),

            onLevel: (level) => { currentLevel = level; setRingIntensity(level); },
            onPlaybackLevel: (level) => { currentLevel = level; setRingIntensity(level); },

            onUploadProgress: (percent) => {
                progressFill.classList.remove('indeterminate');
                progressFill.style.width = `${percent}%`;
                if (percent >= 100) applyState('thinking');
            },

            onTranscript: (text) => appendBubble('user', text),

            onResponse: (turn) => appendBubble('assistant', turn.transcript),

            onError: (error) => showToast(error.message || 'Something went wrong.'),
        });

        widget.start().then(() => { talkBtn.disabled = false; }).catch(() => {});

        function beginTalk(event) {
            event.preventDefault();
            if (talkBtn.disabled || widget.state !== 'ready') return;
            widget.startRecording().catch((error) => showToast(error.message));
        }

        function endTalk(event) {
            event.preventDefault();
            if (widget.state !== 'recording') return;
            widget.stopRecording().catch((error) => showToast(error.message));
        }

        talkBtn.addEventListener('mousedown', beginTalk);
        talkBtn.addEventListener('mouseup', endTalk);
        talkBtn.addEventListener('mouseleave', (e) => { if (widget.state === 'recording') endTalk(e); });
        talkBtn.addEventListener('touchstart', beginTalk, {passive: false});
        talkBtn.addEventListener('touchend', endTalk);

        window.addEventListener('keydown', (e) => {
            if (e.code === 'Space' && !e.repeat && document.activeElement !== talkBtn) beginTalk(e);
        });
        window.addEventListener('keyup', (e) => {
            if (e.code === 'Space') endTalk(e);
        });

        stopSpeakingBtn.addEventListener('click', () => widget.stopSpeaking());

        applyState('idle');
    </script>
</body>
</html>
