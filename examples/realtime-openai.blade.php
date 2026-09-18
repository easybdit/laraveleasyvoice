<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LaravelEasyVoice realtime example (OpenAI)</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 480px; margin: 60px auto; text-align: center; }
        button { font-size: 16px; padding: 14px 24px; border-radius: 8px; cursor: pointer; margin: 6px; }
        #status { margin: 20px 0; color: #555; }
        #log { margin-top: 20px; text-align: left; background: #f5f5f5; padding: 16px; border-radius: 8px; min-height: 120px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap; }
        #level { width: 100%; height: 8px; background: #eee; border-radius: 4px; overflow: hidden; margin-top: 10px; }
        #level-bar { height: 100%; width: 0%; background: #4caf50; transition: width 0.05s linear; }
    </style>
</head>
<body>
    <h1>📡 Realtime voice (OpenAI)</h1>
    <p id="status">Not connected</p>
    <button id="connect">Connect</button>
    <button id="interrupt">Interrupt</button>
    <button id="disconnect">Disconnect</button>
    <div id="level"><div id="level-bar"></div></div>
    <div id="log"></div>

    <script src="/vendor/laraveleasyvoice/voice-realtime.js"></script>
    <script>
        const statusEl = document.getElementById('status');
        const logEl = document.getElementById('log');
        const levelBar = document.getElementById('level-bar');

        function log(line) {
            logEl.textContent += line + '\n';
            logEl.scrollTop = logEl.scrollHeight;
        }

        let session = null;

        document.getElementById('connect').addEventListener('click', async () => {
            session = new VoiceRealtimeSession({
                onConnected: () => { statusEl.textContent = 'Connected - start talking'; log('[connected]'); },
                onEvent: (event) => log('event: ' + event.type),
                onRemoteLevel: (level) => { levelBar.style.width = (level * 100) + '%'; },
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

        document.getElementById('interrupt').addEventListener('click', () => {
            if (session) session.interrupt();
        });

        document.getElementById('disconnect').addEventListener('click', () => {
            if (session) session.close();
        });
    </script>
</body>
</html>
