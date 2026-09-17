<?php

namespace EasyAI\LaravelVoice\Http\Controllers;

use EasyAI\LaravelVoice\Exceptions\ConnectionException;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Exceptions\VoiceException;
use EasyAI\LaravelVoice\Exceptions\VoiceLimitExceededException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Http\Controllers\Concerns\AuthorizesVoiceSession;
use EasyAI\LaravelVoice\Models\VoiceSession;
use EasyAI\LaravelVoice\Models\VoiceTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoiceTurnController extends Controller
{
    use AuthorizesVoiceSession;

    public function store(Request $request, VoiceSession $session): JsonResponse
    {
        $this->authorizeVoiceSession($request, $session);

        $maxKb = (int) config('voice.routes.max_upload_kb', 25600);

        $request->validate([
            'audio' => ['required', 'file', 'mimes:mp3,mpga,wav,m4a,webm,ogg,flac', "max:{$maxKb}"],
        ]);

        try {
            $turn = Voice::agent($session->agent)->handleTurn($session, $request->file('audio')->getRealPath());
        } catch (VoiceLimitExceededException $e) {
            return response()->json(['error' => $e->getMessage()], 429);
        } catch (ProviderException|ConnectionException $e) {
            report($e);

            return response()->json(['error' => 'The voice provider is temporarily unavailable.'], 502);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (VoiceException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json([
            'id' => $turn->id,
            'transcript' => $turn->transcript,
            'audio_url' => $turn->audio_path
                ? route('voice.turns.audio', ['session' => $session->id, 'turn' => $turn->id])
                : null,
            'tool_calls' => $turn->tool_calls,
            'latency_ms' => $turn->latency_ms,
        ]);
    }

    public function audio(Request $request, VoiceSession $session, VoiceTurn $turn): StreamedResponse
    {
        $this->authorizeVoiceSession($request, $session);

        if ((int) $turn->voice_session_id !== (int) $session->id || ! $turn->audio_path) {
            abort(404);
        }

        $disk = config('voice.storage.disk', 'local');

        abort_unless(Storage::disk($disk)->exists($turn->audio_path), 404);

        return Storage::disk($disk)->response($turn->audio_path);
    }
}
