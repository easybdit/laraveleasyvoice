<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LaravelEasyVoice realtime example (Deepgram)</title>
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

        .header { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }

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

        .header h1 { font-size: 17px; margin: 0; font-weight: 600; letter-spacing: 0.2px; }
        .header p { margin: 1px 0 0; font-size: 12.5px; color: var(--muted); }

        .status-row { display: flex; align-items: center; justify-content: center; gap: 8px; margin: 26px 0 10px; }

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

        .status-pill .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        .status-pill[data-state="connecting"] { background: rgba(242, 181, 60, 0.16); color: #ffd489; }
        .status-pill[data-state="connecting"] .dot { animation: blink 1s ease-in-out infinite; }
        .status-pill[data-state="user-speaking"] { background: rgba(124, 108, 240, 0.2); color: #d3cdfd; }
        .status-pill[data-state="user-speaking"] .dot { animation: blink 0.7s ease-in-out infinite; }
        .status-pill[data-state="agent-thinking"] { background: rgba(242, 181, 60, 0.16); color: #ffd489; }
        .status-pill[data-state="agent-thinking"] .dot { animation: blink 0.6s ease-in-out infinite; }
        .status-pill[data-state="agent-speaking"] { background: rgba(79, 209, 139, 0.16); color: #9df0c1; }
        .status-pill[data-state="listening"] { background: rgba(79, 209, 197, 0.14); color: #a8ece5; }
        .status-pill[data-state="error"] { background: rgba(240, 97, 124, 0.18); color: #ffb0c0; }
        .status-pill[data-state="closed"] { background: rgba(255, 255, 255, 0.06); color: var(--muted); }

        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }

        .stage { position: relative; display: flex; flex-direction: column; align-items: center; padding: 14px 0 6px; }

        .mic-wrap { position: relative; width: 168px; height: 168px; display: flex; align-items: center; justify-content: center; }

        .ring {
            position: absolute;
            border-radius: 50%;
            border: 1.5px solid var(--accent-2);
            opacity: 0;
            transform: scale(0.72);
            pointer-events: none;
        }
        .mic-wrap.is-live .ring { animation: breathe 2.4s ease-out infinite; }
        .mic-wrap.is-live #ring-2 { animation-delay: 1.2s; }
        @keyframes breathe {
            0% { opacity: 0.55; transform: scale(0.72); }
            70% { opacity: 0; transform: scale(1.28); }
            100% { opacity: 0; transform: scale(1.28); }
        }

        .call-btn {
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
        .call-btn:disabled { cursor: progress; filter: saturate(0.5); }
        .call-btn:active:not(:disabled) { transform: scale(0.96); }
        .call-btn svg { width: 36px; height: 36px; stroke: white; transition: transform 0.2s ease; }

        .call-btn[data-state="listening"] { background: linear-gradient(150deg, var(--accent-2), #2b9d94); }
        .call-btn[data-state="user-speaking"] { background: linear-gradient(150deg, var(--accent), #5847c9); }
        .call-btn[data-state="agent-thinking"] { background: linear-gradient(150deg, var(--warn), #b9821e); }
        .call-btn[data-state="agent-speaking"] { background: linear-gradient(150deg, var(--ok), #2f9e63); }
        .call-btn[data-state="error"] { background: linear-gradient(150deg, var(--danger), #b6395a); }
        .call-btn.is-connected svg { transform: rotate(135deg); }

        .hint { margin-top: 14px; font-size: 12.5px; color: var(--muted); text-align: center; min-height: 16px; }

        .dual-bars { display: flex; gap: 22px; margin-top: 18px; }
        .bar-group { display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .bar-label { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--muted); }
        .bars { display: flex; align-items: flex-end; gap: 4px; height: 30px; }
        .bars span { width: 4px; border-radius: 3px; height: 4px; transition: height 0.08s ease; }
        #local-bars span { background: linear-gradient(180deg, var(--accent-2), var(--accent)); }
        #remote-bars span { background: linear-gradient(180deg, #9df0c1, var(--ok)); }

        .controls { display: flex; gap: 10px; margin-top: 20px; }
        .ctrl-btn {
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text);
            font-size: 12.5px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s ease, opacity 0.2s ease, border-color 0.2s ease;
        }
        .ctrl-btn:hover:not(:disabled) { background: rgba(255, 255, 255, 0.09); }
        .ctrl-btn:disabled { opacity: 0.35; cursor: not-allowed; }
        .ctrl-btn.is-active { background: rgba(240, 97, 124, 0.16); border-color: rgba(240, 97, 124, 0.4); color: #ffb0c0; }

        .transcript {
            margin-top: 22px;
            max-height: 240px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-right: 2px;
        }
        .transcript:empty { display: none; }
        .transcript::-webkit-scrollbar { width: 6px; }
        .transcript::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 3px; }

        .bubble { max-width: 84%; padding: 10px 13px; border-radius: 14px; font-size: 13.5px; line-height: 1.45; animation: rise 0.25s ease; }
        @keyframes rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
        .bubble.user { align-self: flex-end; background: linear-gradient(135deg, var(--accent), #5847c9); border-bottom-right-radius: 4px; }
        .bubble.assistant { align-self: flex-start; background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.08); border-bottom-left-radius: 4px; }
        .bubble .role { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.6px; opacity: 0.6; margin-bottom: 3px; }

        details.dev-log { margin-top: 22px; }
        details.dev-log summary {
            cursor: pointer;
            font-size: 12px;
            color: var(--muted);
            list-style: none;
            display: flex;
            align-items: center;
            gap: 6px;
            user-select: none;
        }
        details.dev-log summary::-webkit-details-marker { display: none; }
        details.dev-log summary::before { content: '▸'; display: inline-block; transition: transform 0.15s ease; }
        details.dev-log[open] summary::before { transform: rotate(90deg); }
        details.dev-log summary:hover { color: var(--text); }
        #log {
            margin-top: 10px;
            text-align: left;
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.07);
            padding: 12px 14px;
            border-radius: 10px;
            max-height: 200px;
            overflow-y: auto;
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: 11px;
            line-height: 1.6;
            color: #b9bce0;
            white-space: pre-wrap;
            word-break: break-word;
        }
        #log::-webkit-scrollbar { width: 6px; }
        #log::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 3px; }

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
        .toast.on { opacity: 1; transform: translateX(-50%) translateY(0); }

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
        .brand-footer a { color: var(--accent-2); text-decoration: none; font-weight: 600; }
        .brand-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="logo">🎧</div>
            <div>
                <h1>LaravelEasyVoice</h1>
                <p>Realtime voice &middot; Deepgram Voice Agent</p>
            </div>
        </div>

        <div class="status-row">
            <span class="status-pill" id="status-pill" data-state="idle">
                <span class="dot"></span>
                <span id="status-text">Not connected</span>
            </span>
        </div>

        <div class="stage">
            <div class="mic-wrap" id="mic-wrap">
                <div class="ring" id="ring-1"></div>
                <div class="ring" id="ring-2"></div>
                <button id="call-btn" class="call-btn" data-state="idle" title="Connect">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                </button>
            </div>

            <div class="hint" id="hint">Press connect to start talking to the agent</div>

            <div class="dual-bars">
                <div class="bar-group">
                    <span class="bar-label">You</span>
                    <div class="bars" id="local-bars">
                        <span></span><span></span><span></span><span></span><span></span>
                    </div>
                </div>
                <div class="bar-group">
                    <span class="bar-label">Agent</span>
                    <div class="bars" id="remote-bars">
                        <span></span><span></span><span></span><span></span><span></span>
                    </div>
                </div>
            </div>

            <div class="controls">
                <button id="mute-btn" class="ctrl-btn" disabled>🎙️ Mute</button>
                <button id="interrupt-btn" class="ctrl-btn" disabled>⏹ Interrupt</button>
            </div>
        </div>

        <div class="transcript" id="transcript"></div>

        <details class="dev-log">
            <summary>Developer event log</summary>
            <div id="log"></div>
        </details>

        <div class="brand-footer">
            Built with <strong>LaravelEasyVoice</strong> &middot;
            <a href="https://github.com/easybdit/laraveleasyvoice" target="_blank" rel="noopener">github.com/easybdit/laraveleasyvoice</a>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script src="/vendor/laraveleasyvoice/voice-realtime-deepgram.js"></script>
    <script>
        const pill = document.getElementById('status-pill');
        const statusText = document.getElementById('status-text');
        const hint = document.getElementById('hint');
        const micWrap = document.getElementById('mic-wrap');
        const callBtn = document.getElementById('call-btn');
        const muteBtn = document.getElementById('mute-btn');
        const interruptBtn = document.getElementById('interrupt-btn');
        const localBars = [...document.querySelectorAll('#local-bars span')];
        const remoteBars = [...document.querySelectorAll('#remote-bars span')];
        const transcriptEl = document.getElementById('transcript');
        const logEl = document.getElementById('log');
        const toastEl = document.getElementById('toast');

        const STATE_LABELS = {
            idle: 'Not connected',
            connecting: 'Connecting…',
            listening: 'Listening',
            'user-speaking': 'You’re speaking',
            'agent-thinking': 'Agent is thinking…',
            'agent-speaking': 'Agent is speaking',
            error: 'Connection error',
            closed: 'Disconnected',
        };

        let session = null;
        let muted = false;
        let toastTimer = null;

        function setState(state) {
            pill.dataset.state = state;
            statusText.textContent = STATE_LABELS[state] ?? state;
            callBtn.dataset.state = state;

            const live = ['listening', 'user-speaking', 'agent-thinking', 'agent-speaking'].includes(state);
            micWrap.classList.toggle('is-live', live);
            callBtn.classList.toggle('is-connected', live || state === 'connecting');

            muteBtn.disabled = !live;
            interruptBtn.disabled = state !== 'agent-speaking';

            if (state === 'idle') hint.textContent = 'Press connect to start talking to the agent';
            else if (state === 'connecting') hint.textContent = 'Requesting a session and opening the connection…';
            else if (state === 'listening') hint.textContent = 'Just start talking — no need to press anything';
            else if (state === 'user-speaking') hint.textContent = 'Listening to you…';
            else if (state === 'agent-thinking') hint.textContent = 'The agent is working on a reply…';
            else if (state === 'agent-speaking') hint.textContent = 'The agent is replying — talk anytime to interrupt';
            else if (state === 'closed') hint.textContent = 'Call ended. Press connect to start a new one.';
        }

        function appendBubble(text, role) {
            const bubble = document.createElement('div');
            bubble.className = 'bubble ' + (role === 'user' ? 'user' : 'assistant');
            bubble.innerHTML = '<span class="role">' + (role === 'user' ? 'You' : 'Agent') + '</span>';
            bubble.append(document.createTextNode(text));
            transcriptEl.appendChild(bubble);
            transcriptEl.scrollTop = transcriptEl.scrollHeight;
        }

        function logLine(line) {
            const time = new Date().toLocaleTimeString([], { hour12: false });
            logEl.textContent += '[' + time + '] ' + line + '\n';
            logEl.scrollTop = logEl.scrollHeight;
        }

        function showToast(message) {
            toastEl.textContent = message;
            toastEl.classList.add('on');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toastEl.classList.remove('on'), 4000);
        }

        // A single aggregate 0-1 level (onLocalLevel/onRemoteLevel) is all
        // the client exposes - there's no real per-frequency data to bar-
        // chart, so each bar is driven by that one value with a small,
        // fixed per-bar weight to make the group move like a livelier
        // waveform rather than 5 identical bars in lockstep.
        const BAR_WEIGHTS = [0.55, 0.85, 1, 0.8, 0.6];
        function paintBars(bars, level) {
            bars.forEach((bar, i) => {
                const height = Math.max(4, Math.min(28, level * 28 * BAR_WEIGHTS[i]));
                bar.style.height = height + 'px';
            });
        }

        callBtn.addEventListener('click', async () => {
            if (session) {
                session.close();
                return;
            }

            setState('connecting');
            callBtn.disabled = true;

            session = new VoiceRealtimeDeepgramSession({
                greeting: 'Hello! How can I help you today?',
                onConnected: () => {
                    setState('listening');
                    callBtn.disabled = false;
                    logLine('connected');
                },
                onEvent: (event) => {
                    logLine(event.type ?? JSON.stringify(event));

                    switch (event.type) {
                        case 'UserStartedSpeaking': setState('user-speaking'); break;
                        case 'AgentThinking': setState('agent-thinking'); break;
                        case 'AgentStartedSpeaking': setState('agent-speaking'); break;
                        case 'AgentAudioDone': setState('listening'); break;
                    }
                },
                onTranscript: (text, role) => appendBubble(text, role),
                onLocalLevel: (level) => paintBars(localBars, level),
                onRemoteLevel: (level) => paintBars(remoteBars, level),
                onError: (error) => {
                    setState('error');
                    callBtn.disabled = false;
                    logLine('error: ' + error.message);
                    showToast(error.message);
                },
                onClose: () => {
                    setState('closed');
                    callBtn.disabled = false;
                    paintBars(localBars, 0);
                    paintBars(remoteBars, 0);
                    logLine('closed');
                    session = null;
                    muted = false;
                    muteBtn.textContent = '🎙️ Mute';
                },
            });

            try {
                await session.connect();
            } catch (error) {
                setState('error');
                callBtn.disabled = false;
                logLine('connect failed: ' + error.message);
                showToast(error.message);
                session = null;
            }
        });

        muteBtn.addEventListener('click', () => {
            if (!session) return;
            muted = !muted;
            session.setMuted(muted);
            muteBtn.textContent = muted ? '🔇 Unmute' : '🎙️ Mute';
            muteBtn.classList.toggle('is-active', muted);
        });

        interruptBtn.addEventListener('click', () => {
            if (session) session.interrupt();
        });

        setState('idle');
    </script>
</body>
</html>
