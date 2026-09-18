<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LaravelEasyVoice realtime example (Deepgram)</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 480px; margin: 60px auto; text-align: center; }
        button { font-size: 16px; padding: 14px 24px; border-radius: 8px; cursor: pointer; margin: 6px; }
        #status { margin: 20px 0; color: #555; }
        #log { margin-top: 20px; text-align: left; background: #f5f5f5; padding: 16px; border-radius: 8px; min-height: 120px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap; }
        .levels { display: flex; gap: 8px; margin-top: 10px; }
        .level { flex: 1; height: 8px; background: #eee; border-radius: 4px; overflow: hidden; }
        .level-bar { height: 100%; width: 0%; transition: width 0.05s linear; }
        #local-bar { background: #2196f3; }
        #remote-bar { background: #4caf50; }
    </style>
</head>
<body>
    <h1>🎙️ Realtime voice (Deepgram)</h1>
    <p id="status">Not connected</p>
    <button id="connect">Connect</button>
    <button id="mute">Mute</button>
    <button id="interrupt">Interrupt</button>
    <button id="disconnect">Disconnect</button>
    <div class="levels">
        <div class="level"><div id="local-bar" class="level-bar"></div></div>
        <div class="level"><div id="remote-bar" class="level-bar"></div></div>
    </div>
    <div id="log"></div>

    <script src="/vendor/laraveleasyvoice/voice-realtime-deepgram.js"></script>
    <script>
        const statusEl = document.getElementById('status');
        const logEl = document.getElementById('log');
        const localBar = document.getElementById('local-bar');
        const remoteBar = document.getElementById('remote-bar');
        const muteBtn = document.getElementById('mute');

        function log(line) {
            logEl.textContent += line + '\n';
            logEl.scrollTop = logEl.scrollHeight;
        }

        let session = null;
        let muted = false;

        document.getElementById('connect').addEventListener('click', async () => {
            session = new VoiceRealtimeDeepgramSession({
                greeting: 'Hello! How can I help you today?',
                onConnected: () => { statusEl.textContent = 'Connected - start talking'; log('[connected]'); },
                onEvent: (event) => log('event: ' + event.type),
                onTranscript: (text, role) => log('[' + role + '] ' + text),
                onLocalLevel: (level) => { localBar.style.width = (level * 100) + '%'; },
                onRemoteLevel: (level) => { remoteBar.style.width = (level * 100) + '%'; },
                onError: (error) => { log('[error] ' + error.message); statusEl.textContent = 'Error: ' + error.message; },
                onClose: () => { statusEl.textContent = 'Disconnected'; log('[closed]'); },
            });

            statusEl.textContent = 'Connecting...';
            try {
                await session.connect();
            } catch (error) {
                log('[connect failed] ' + error.message);
            }
        });

        muteBtn.addEventListener('click', () => {
            if (!session) return;
            muted = !muted;
            session.setMuted(muted);
            muteBtn.textContent = muted ? 'Unmute' : 'Mute';
        });

        document.getElementById('interrupt').addEventListener('click', () => {
            if (session) session.interrupt();
        });

        document.getElementById('disconnect').addEventListener('click', () => {
            if (session) session.close();
        });
    </script>
</body>
</html>
