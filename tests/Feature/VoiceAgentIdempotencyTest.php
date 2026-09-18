<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Exceptions\VoiceException;
use EasyAI\LaravelVoice\Exceptions\VoiceLimitExceededException;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\Models\VoiceTurn;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 9B: $options['idempotency_key'] on VoiceAgent::handleTurn() - a
 * second call for the same session with the same key never re-runs STT/
 * LLM/tools/TTS or charges usage twice. See claimNextTurn()/
 * resolveDuplicateTurn()'s own docblocks for the exact contract.
 */
class VoiceAgentIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Voice::registerAgent('receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')
                ->systemPrompt('You are a helpful receptionist.')
                ->limits(maxTurns: 50, maxSessionSeconds: 3600);
        });
    }

    protected function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice_test_').'.mp3';
        file_put_contents($path, 'fake-audio-bytes');

        return $path;
    }

    protected function fakeChatCompletion(string $content): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'What is the admission policy?']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => $content]]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response('binary-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);
    }

    public function test_no_idempotency_key_processes_each_turn_independently(): void
    {
        $this->fakeChatCompletion('Open enrollment.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        $first = $this->makeTempAudioFile();
        $second = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $first);
            $agent->handleTurn($session, $second);
        } finally {
            unlink($first);
            unlink($second);
        }

        // No key on either call - both are genuinely new turns, not
        // deduped against each other.
        $this->assertSame(4, VoiceTurn::where('voice_session_id', $session->id)->count());
        $this->assertSame([1, 2, 3, 4], VoiceTurn::where('voice_session_id', $session->id)->orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_a_turn_with_an_idempotency_key_completes_normally(): void
    {
        $this->fakeChatCompletion('Open enrollment.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $assistantTurn = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('Open enrollment.', $assistantTurn->transcript);
        $this->assertSame('completed', $assistantTurn->status);

        $userTurn = VoiceTurn::where('voice_session_id', $session->id)->where('speaker', 'user')->first();
        $this->assertSame('attempt-1', $userTurn->idempotency_key);
    }

    public function test_a_completed_duplicate_returns_the_original_turn_without_reprocessing(): void
    {
        $this->fakeChatCompletion('Open enrollment.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $first = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
            $second = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        // Same row, same content - not a freshly processed one.
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->transcript, $second->transcript);
        $this->assertSame($first->audio_path, $second->audio_path);
        $this->assertSame($first->tool_calls, $second->tool_calls);
        $this->assertSame($first->latency_ms, $second->latency_ms);

        // Exactly one STT + one chat + one speech call total across BOTH
        // handleTurn() calls - the second never touched any provider.
        Http::assertSentCount(3);

        // No new rows and no new sequence numbers were consumed by the
        // duplicate.
        $this->assertSame(2, VoiceTurn::where('voice_session_id', $session->id)->count());
        $this->assertSame([1, 2], VoiceTurn::where('voice_session_id', $session->id)->orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_a_completed_duplicate_does_not_double_charge_usage_or_cost(): void
    {
        config(['ai.pricing.openai.gpt-4o-mini' => ['input' => 0.01, 'output' => 0.03]]);
        $this->fakeChatCompletion('Open enrollment.');

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
            $session->refresh();
            $promptTokensAfterFirst = $session->total_prompt_tokens;
            $completionTokensAfterFirst = $session->total_completion_tokens;
            $costAfterFirst = $session->estimated_cost;

            $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        $session->refresh();
        $this->assertSame($promptTokensAfterFirst, $session->total_prompt_tokens);
        $this->assertSame($completionTokensAfterFirst, $session->total_completion_tokens);
        $this->assertEqualsWithDelta($costAfterFirst, $session->estimated_cost, 0.000001);
    }

    public function test_a_pending_duplicate_returns_a_conflict_without_reprocessing(): void
    {
        Http::fake(); // any outbound call at all fails the assertion below

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        // Simulates "another attempt for this key is still in flight" -
        // exactly the row shape claimNextTurn() leaves behind between
        // creating the pending user turn and STT completing.
        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'pending',
            'idempotency_key' => 'attempt-1',
        ]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $this->expectException(VoiceException::class);
            $this->expectExceptionMessage('still processing');

            $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        Http::assertNothingSent();
    }

    public function test_an_stt_failed_duplicate_returns_the_stored_failure_without_retrying(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['error' => 'bad audio'], 500),
        ]);

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $originalMessage = null;

            try {
                $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
                $this->fail('Expected the first attempt to throw.');
            } catch (\Throwable $e) {
                $originalMessage = $e->getMessage();
            }

            Http::assertSentCount(1);

            try {
                $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
                $this->fail('Expected the duplicate attempt to throw the stored failure.');
            } catch (VoiceException $e) {
                // The exact original error is replayed, not a generic message.
                $this->assertSame($originalMessage, $e->getMessage());
            }
        } finally {
            unlink($audioPath);
        }

        // Still exactly one attempt was ever sent to the STT provider.
        Http::assertSentCount(1);
    }

    public function test_an_llm_failed_duplicate_returns_the_stored_failure_without_retrying(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello there']),
            'api.openai.com/v1/chat/completions' => Http::response(['error' => 'server error'], 500),
        ]);

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $originalMessage = null;

            try {
                $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
                $this->fail('Expected the first attempt to throw.');
            } catch (\Throwable $e) {
                $originalMessage = $e->getMessage();
            }

            Http::assertSentCount(2); // STT + the failed chat call

            try {
                $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
                $this->fail('Expected the duplicate attempt to throw the stored failure.');
            } catch (VoiceException $e) {
                $this->assertSame($originalMessage, $e->getMessage());
            }
        } finally {
            unlink($audioPath);
        }

        Http::assertSentCount(2);
    }

    public function test_a_completed_duplicate_preserves_a_null_audio_path_from_a_prior_tts_failure(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello there']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hi! How can I help?']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ]),
            'api.openai.com/v1/audio/speech' => Http::response(['error' => 'server error'], 500),
        ]);

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            try {
                $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
                $this->fail('Expected the first attempt to throw (TTS failure).');
            } catch (\Throwable) {
                // Expected - existing behavior: the text answer is already
                // saved as 'completed' even though TTS failed and the
                // exception still propagates.
            }

            $second = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        // Reconstructed, not re-thrown as a stored failure - the text
        // answer genuinely succeeded, exactly the existing, unchanged
        // TTS-failure persistence contract.
        $this->assertSame('completed', $second->status);
        $this->assertSame('Hi! How can I help?', $second->transcript);
        $this->assertNull($second->audio_path);

        Http::assertSentCount(3); // no retry of any provider on the duplicate
    }

    public function test_a_duplicate_lookup_sees_a_key_committed_by_another_process_with_zero_provider_calls(): void
    {
        Http::fake(); // any outbound call at all fails the assertion below

        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);

        // Simulates a genuinely concurrent request that already claimed
        // this key and fully completed before this call ever started -
        // written directly, bypassing handleTurn() entirely, the same
        // "out from under the caller" technique Phase 9A's cost-race test
        // uses. Proves the duplicate lookup is a fresh database read
        // (exactly what a real concurrent request racing in after that
        // commit would also see), not reliant on any in-process state.
        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 1,
            'speaker' => 'user',
            'status' => 'completed',
            'transcript' => 'Hello there',
            'idempotency_key' => 'attempt-1',
        ]);
        VoiceTurn::create([
            'voice_session_id' => $session->id,
            'sequence' => 2,
            'speaker' => 'assistant',
            'status' => 'completed',
            'transcript' => 'Hi! How can I help?',
            'latency_ms' => 120,
        ]);

        $audioPath = $this->makeTempAudioFile();

        try {
            $result = $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);
        } finally {
            unlink($audioPath);
        }

        $this->assertSame('Hi! How can I help?', $result->transcript);
        Http::assertNothingSent();
    }

    /**
     * A brand-new key (never seen before for this session) must go
     * through the normal active-session check like any other request -
     * the idempotency lookup only ever short-circuits for a key that
     * already matches an existing turn.
     */
    public function test_a_fresh_idempotency_key_does_not_bypass_an_ended_session(): void
    {
        $agent = Voice::agent('receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $agent->endSession($session);

        Http::fake(); // any outbound call at all fails the assertion below

        $audioPath = $this->makeTempAudioFile();

        try {
            try {
                $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'brand-new-key']);
                $this->fail('Expected the ended session to reject a fresh key.');
            } catch (VoiceException $e) {
                $this->assertStringContainsString('is not active', $e->getMessage());
            }
        } finally {
            unlink($audioPath);
        }

        Http::assertNothingSent();
        $this->assertSame(0, VoiceTurn::where('voice_session_id', $session->id)->count());
    }

    /**
     * Same guarantee for maxTurns specifically: a fresh key must not let
     * a caller slip an extra turn past an already-exhausted limit.
     */
    public function test_a_fresh_idempotency_key_does_not_bypass_max_turns(): void
    {
        Voice::registerAgent('single-turn-receptionist', function ($agent) {
            $agent->stt('openai')->tts('openai')->llm('openai')->limits(maxTurns: 1, maxSessionSeconds: 3600);
        });

        $this->fakeChatCompletion('Open enrollment.');

        $agent = Voice::agent('single-turn-receptionist');
        $session = $agent->startSession(['user_id' => 1]);
        $audioPath = $this->makeTempAudioFile();

        try {
            $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-1']);

            try {
                $agent->handleTurn($session, $audioPath, ['idempotency_key' => 'attempt-2-brand-new']);
                $this->fail('Expected the exhausted maxTurns limit to reject a fresh key.');
            } catch (VoiceLimitExceededException) {
                // Expected.
            }
        } finally {
            unlink($audioPath);
        }

        $session->refresh();
        $this->assertSame('ended', $session->status);

        // Only the first exchange's pair exists - the fresh key's attempt
        // never created a turn.
        $this->assertSame(2, VoiceTurn::where('voice_session_id', $session->id)->count());
    }
}
