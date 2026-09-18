<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Models\VoiceTurn;
use EasyAI\LaravelVoice\Tests\Support\FakeUser;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 9B over HTTP: the optional Idempotency-Key request header, read by
 * VoiceTurnController::store() and passed through to VoiceAgent::
 * handleTurn() unchanged - see that method's own docblock for the exact
 * contract this exercises end to end.
 */
class VoiceHttpIdempotencyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.routes.enabled', true);
        $app['config']->set('voice.routes.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Voice::registerAgent('receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')->limits(50, 3600);
        });
    }

    protected function actingAsFakeUser(int $id = 7): FakeUser
    {
        $user = new FakeUser($id);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_repeated_idempotency_key_over_http_returns_the_identical_response(): void
    {
        $this->actingAsFakeUser(7);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello there']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hi! How can I help?']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');
        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        $first = $this->withHeaders(['Idempotency-Key' => 'client-attempt-1'])
            ->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio]);

        $second = $this->withHeaders(['Idempotency-Key' => 'client-attempt-1'])
            ->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio]);

        $first->assertOk();
        $second->assertOk();

        // Byte-for-byte the same response shape and content on the retry.
        $this->assertSame($first->json(), $second->json());

        // Exactly one STT + one chat + one speech call across BOTH requests.
        Http::assertSentCount(3);

        // Only one user/assistant turn pair was ever created.
        $this->assertSame(2, VoiceTurn::where('voice_session_id', $sessionId)->count());
    }

    public function test_a_still_processing_idempotency_key_over_http_returns_409(): void
    {
        $this->actingAsFakeUser(7);

        Http::fake(); // any outbound call at all fails the assertion below

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');

        // Simulates a prior attempt for this key still being processed -
        // the exact row shape a pending STT/LLM/TTS step leaves behind.
        VoiceTurn::create([
            'voice_session_id' => $sessionId,
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'pending',
            'idempotency_key' => 'client-attempt-1',
        ]);

        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        $response = $this->withHeaders(['Idempotency-Key' => 'client-attempt-1'])
            ->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio]);

        $response->assertStatus(409);
        Http::assertNothingSent();
    }

    /**
     * The intermediate state between STT succeeding and the assistant
     * turn being created: the keyed user turn is already 'completed',
     * but no assistant turn exists yet. A duplicate request for this key
     * must be treated as "still processing" (409), the same as the
     * earlier user-turn-still-pending window above - not mistaken for a
     * failure or a success just because the user turn itself succeeded.
     */
    public function test_a_completed_user_turn_with_no_assistant_turn_yet_returns_409(): void
    {
        $this->actingAsFakeUser(7);

        Http::fake(); // any outbound call at all fails the assertion below

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');

        VoiceTurn::create([
            'voice_session_id' => $sessionId,
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'completed',
            'transcript' => 'Hello there',
            'idempotency_key' => 'client-attempt-1',
        ]);
        // Deliberately no assistant turn (sequence 2) yet.

        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        $response = $this->withHeaders(['Idempotency-Key' => 'client-attempt-1'])
            ->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio]);

        $response->assertStatus(409);
        Http::assertNothingSent();
    }

    /**
     * Idempotency-Key sent with an empty value must behave exactly like
     * no key at all - an empty string is otherwise a normal, non-NULL
     * value that would incorrectly self-collide under the unique index.
     */
    public function test_an_empty_idempotency_key_header_is_treated_as_no_key(): void
    {
        $this->actingAsFakeUser(7);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello there']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hi! How can I help?']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');
        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        // Missing header entirely.
        $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio])->assertOk();

        // Header present but empty - must not be treated as a real,
        // self-colliding key either.
        $this->withHeaders(['Idempotency-Key' => ''])
            ->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio])
            ->assertOk();

        // A real, non-empty key is stored unchanged.
        $this->withHeaders(['Idempotency-Key' => 'client-attempt-3'])
            ->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio])
            ->assertOk();

        $userTurns = VoiceTurn::where('voice_session_id', $sessionId)
            ->where('speaker', 'user')
            ->orderBy('sequence')
            ->pluck('idempotency_key')
            ->all();

        $this->assertSame([null, null, 'client-attempt-3'], $userTurns);

        // All three were genuinely new, independent turns - none deduped
        // against each other.
        $this->assertSame(6, VoiceTurn::where('voice_session_id', $sessionId)->count());
    }

    public function test_omitting_the_idempotency_key_header_behaves_exactly_as_before(): void
    {
        $this->actingAsFakeUser(7);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello there']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hi! How can I help?']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $sessionId = $this->postJson('/voice/sessions', ['agent' => 'receptionist'])->json('id');
        $audio = UploadedFile::fake()->create('speech.mp3', 10, 'audio/mpeg');

        // No Idempotency-Key header at all - two requests are two genuinely
        // separate turns, not deduped.
        $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio])->assertOk();
        $this->post("/voice/sessions/{$sessionId}/turns", ['audio' => $audio])->assertOk();

        Http::assertSentCount(6);
        $this->assertSame(4, VoiceTurn::where('voice_session_id', $sessionId)->count());
    }
}
