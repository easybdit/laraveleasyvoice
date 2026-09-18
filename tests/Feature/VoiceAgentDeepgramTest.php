<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Events\ResponseSynthesized;
use EasyAI\LaravelVoice\Events\SpeechTranscribed;
use EasyAI\LaravelVoice\Events\VoiceError;
use EasyAI\LaravelVoice\Exceptions\ProviderException;
use EasyAI\LaravelVoice\Exceptions\VoiceException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Proves the existing, already-provider-agnostic VoiceAgent::handleTurn()
 * pipeline (audio -> STT -> LaravelEasyAI -> TTS -> stored AudioResult,
 * built in Phase 7, unchanged since) works end-to-end with Deepgram on
 * both the STT and TTS side - with zero changes to VoiceAgent itself.
 * Every assertion here exercises the agent through its existing public
 * API (->stt('deepgram')->tts('deepgram')), the same way VoiceAgentTest
 * already does for 'openai' - there is nothing Deepgram-specific in
 * VoiceAgent to test, which is itself the point being proven.
 */
class VoiceAgentDeepgramTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('voice.stt.providers.deepgram', [
            'api_key' => 'test-key',
            'url' => 'https://api.deepgram.com/v1',
            'model' => 'nova-2',
            'timeout' => 10,
            'max_file_size' => 25 * 1024 * 1024,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);

        $app['config']->set('voice.tts.providers.deepgram', [
            'api_key' => 'test-key',
            'url' => 'https://api.deepgram.com/v1',
            'model' => 'aura-2-thalia-en',
            'format' => 'mp3',
            'timeout' => 10,
            'max_input_length' => 2000,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Voice::registerAgent('deepgram-receptionist', function ($agent) {
            $agent->stt('deepgram')->tts('deepgram')->llm('openai')
                ->systemPrompt('You are a helpful receptionist.')
                ->limits(maxTurns: 50, maxSessionSeconds: 3600);
        });
    }

    protected function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.wav';
        file_put_contents($path, 'fake-audio-bytes');

        return $path;
    }

    /** @return array<string, mixed> */
    protected function fakeDeepgramStt(string $transcript): array
    {
        return [
            'api.deepgram.com/v1/listen*' => Http::response([
                'metadata' => ['duration' => 4.2],
                'results' => ['channels' => [['alternatives' => [['transcript' => $transcript]]]]],
            ]),
        ];
    }

    /** @return array<string, mixed> */
    protected function fakeDeepgramTts(): array
    {
        return [
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    protected function fakeChatCompletion(string $content): array
    {
        return [
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => $content]]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
            ]),
        ];
    }

    public function test_it_runs_a_full_deepgram_to_deepgram_voice_turn_and_persists_both_sides(): void
    {
        Event::fake();

        Http::fake(array_merge(
            $this->fakeDeepgramStt('What is the admission policy?'),
            $this->fakeChatCompletion('The admission policy is open enrollment.'),
            $this->fakeDeepgramTts(),
        ));

        $agent = Voice::agent('deepgram-receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $this->assertSame('active', $session->status);

        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        // Deepgram STT was actually called, and the transcript it returned
        // was persisted on the user turn.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.deepgram.com/v1/listen'));

        $userTurn = $session->turns()->where('speaker', 'user')->first();
        $this->assertSame('What is the admission policy?', $userTurn->transcript);
        $this->assertSame('completed', $userTurn->status);

        // LaravelEasyAI received exactly that transcript as the new user message.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            return collect($request['messages'] ?? [])
                ->contains(fn ($m) => ($m['role'] ?? null) === 'user' && ($m['content'] ?? null) === 'What is the admission policy?');
        });

        // The AI's response was persisted on the assistant turn.
        $this->assertSame('assistant', $assistantTurn->speaker);
        $this->assertSame('The admission policy is open enrollment.', $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);

        // Deepgram TTS actually received that exact AI response text as
        // input - not the transcript, not something else.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.deepgram.com/v1/speak')) {
                return false;
            }

            return ($request['text'] ?? null) === 'The admission policy is open enrollment.';
        });

        // Audio exists, and its MIME type was preserved through to the
        // stored file's extension (Deepgram's default mp3 format ->
        // audio/mpeg -> VoiceAgent::storeAudio()'s existing mapping).
        $this->assertNotNull($assistantTurn->audio_path);
        $this->assertStringEndsWith('.mp3', $assistantTurn->audio_path);
        Storage::disk('local')->assertExists($assistantTurn->audio_path);

        $session->refresh();
        $this->assertSame('active', $session->status);

        Event::assertDispatched(SpeechTranscribed::class);
        Event::assertDispatched(ResponseSynthesized::class);
    }

    public function test_deepgram_stt_failure_marks_the_user_turn_failed_and_never_calls_ai_or_tts(): void
    {
        Event::fake();

        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::response(['err_code' => 'INVALID_AUTH', 'err_msg' => 'Invalid credentials.'], 401),
        ]);

        $agent = Voice::agent('deepgram-receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            try {
                $agent->handleTurn($session, $audioPath);
                $this->fail('Expected a ProviderException to be thrown.');
            } catch (ProviderException $e) {
                // Deepgram's own exception, propagated as-is - the STT step
                // never re-wraps, unlike the LLM step.
                $this->assertSame('deepgram', $e->getProvider());
            }
        } finally {
            unlink($audioPath);
        }

        $userTurn = $session->turns()->where('speaker', 'user')->first();
        $this->assertSame('failed', $userTurn->status);
        $this->assertNotNull($userTurn->error_message);

        Event::assertDispatched(VoiceError::class, fn (VoiceError $e) => $e->stage === 'stt');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.deepgram.com/v1/speak'));

        $this->assertSame(0, $session->turns()->where('speaker', 'assistant')->count());
    }

    public function test_ai_failure_after_deepgram_stt_success_marks_assistant_turn_failed_and_never_calls_tts(): void
    {
        Event::fake();

        Http::fake(array_merge(
            $this->fakeDeepgramStt('Hello there'),
            [
                'api.openai.com/v1/chat/completions' => Http::response(
                    ['error' => ['message' => 'upstream-secret-detail']],
                    500
                ),
            ],
        ));

        $agent = Voice::agent('deepgram-receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            try {
                $agent->handleTurn($session, $audioPath);
                $this->fail('Expected an exception to be thrown.');
            } catch (\Throwable $e) {
                // Existing VoiceAgent behavior (Phase 16): LaravelEasyAI's
                // own exception type is re-wrapped into this package's own
                // VoiceException hierarchy before it ever leaves
                // handleTurn() - a caller catching only
                // EasyAI\LaravelVoice\Exceptions\* (as VoiceTurnController
                // does, redacting the message into a generic 502 - already
                // covered by VoiceHttpTest and not duplicated here) must
                // never see a raw EasyAI\LaravelAI\Exceptions\* instance.
                $this->assertInstanceOf(VoiceException::class, $e);
                $this->assertInstanceOf(ProviderException::class, $e);
            }
        } finally {
            unlink($audioPath);
        }

        $userTurn = $session->turns()->where('speaker', 'user')->first();
        $this->assertSame('completed', $userTurn->status);
        $this->assertSame('Hello there', $userTurn->transcript);

        $assistantTurn = $session->turns()->where('speaker', 'assistant')->first();
        $this->assertSame('failed', $assistantTurn->status);

        Event::assertDispatched(VoiceError::class, fn (VoiceError $e) => $e->stage === 'llm');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.deepgram.com/v1/speak'));
    }

    public function test_deepgram_tts_failure_still_persists_the_ai_text_and_fires_voice_error(): void
    {
        Event::fake();

        Http::fake(array_merge(
            $this->fakeDeepgramStt('Hello there'),
            $this->fakeChatCompletion('Hi! How can I help?'),
            ['api.deepgram.com/v1/speak*' => Http::response(['err_msg' => 'server error'], 500)],
        ));

        $agent = Voice::agent('deepgram-receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            try {
                $agent->handleTurn($session, $audioPath);
                $this->fail('Expected a ProviderException to be thrown.');
            } catch (ProviderException $e) {
                $this->assertSame('deepgram', $e->getProvider());
            }
        } finally {
            unlink($audioPath);
        }

        // The LLM's text answer is already saved - a TTS failure never
        // discards it (existing, documented VoiceAgent behavior).
        $assistantTurn = $session->turns()->where('speaker', 'assistant')->first();
        $this->assertSame('Hi! How can I help?', $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);
        $this->assertNull($assistantTurn->audio_path);

        Event::assertDispatched(VoiceError::class, fn (VoiceError $e) => $e->stage === 'tts');
    }

    public function test_conversation_history_from_a_prior_deepgram_turn_is_sent_on_the_next_ai_call(): void
    {
        Http::fake([
            'api.deepgram.com/v1/listen*' => Http::sequence()
                ->push(['results' => ['channels' => [['alternatives' => [['transcript' => 'What is the admission policy?']]]]]])
                ->push(['results' => ['channels' => [['alternatives' => [['transcript' => 'What about fees?']]]]]]),
            'api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => 'The admission policy is open enrollment.']]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => 'Fees are due each semester.']]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ]),
            'api.deepgram.com/v1/speak*' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $agent = Voice::agent('deepgram-receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $firstAudioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $firstAudioPath);
        } finally {
            unlink($firstAudioPath);
        }

        $secondAudioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $secondAudioPath);
        } finally {
            unlink($secondAudioPath);
        }

        $chatRequests = Http::recorded(fn ($request) => str_contains($request->url(), 'chat/completions'));
        $this->assertCount(2, $chatRequests);

        $secondRequest = $chatRequests->last()[0];
        $messages = collect($secondRequest['messages'] ?? []);

        $this->assertTrue($messages->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'What is the admission policy?'));
        $this->assertTrue($messages->contains(fn ($m) => $m['role'] === 'assistant' && $m['content'] === 'The admission policy is open enrollment.'));
        $this->assertTrue($messages->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'What about fees?'));
    }

    public function test_mixed_providers_deepgram_stt_with_openai_tts_works_without_special_casing(): void
    {
        Voice::registerAgent('deepgram-stt-openai-tts', function ($agent) {
            $agent->stt('deepgram')->tts('openai')->llm('openai');
        });

        Http::fake(array_merge(
            $this->fakeDeepgramStt('Hello there'),
            $this->fakeChatCompletion('Hi! How can I help?'),
            ['api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, ['Content-Type' => 'audio/mpeg'])],
        ));

        $agent = Voice::agent('deepgram-stt-openai-tts');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('Hi! How can I help?', $assistantTurn->transcript);
        $this->assertNotNull($assistantTurn->audio_path);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.deepgram.com/v1/listen'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com/v1/audio/speech'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.deepgram.com/v1/speak'));
    }
}
