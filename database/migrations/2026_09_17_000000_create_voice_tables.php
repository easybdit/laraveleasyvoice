<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_sessions')) {
            Schema::create('voice_sessions', function (Blueprint $table) {
                $table->id();

                $table->string('agent')->nullable();
                $table->string('provider_stt', 40)->nullable();
                $table->string('provider_tts', 40)->nullable();
                $table->string('provider_llm', 40)->nullable();
                $table->string('voice', 60)->nullable();
                $table->string('language', 10)->nullable();
                $table->string('channel', 20)->default('web');
                $table->enum('status', ['active', 'ended', 'failed'])->default('active');

                // Optional link into EasyAI's own chat history so a voice
                // session's turns can also surface in a text chat UI/memory
                // window. Nullable and null-on-delete — never required.
                $table->foreignId('chat_session_id')->nullable()
                    ->constrained('ai_chat_sessions')->nullOnDelete();

                // Identity/isolation columns intentionally have NO foreign
                // key, mirroring EasyAI's own ai_chat_sessions table — the
                // host app's user/tenant model is unknown to this package.
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('guest_token', 64)->nullable()->index();

                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();

                $table->unsignedInteger('total_stt_ms')->default(0);
                $table->unsignedInteger('total_tts_ms')->default(0);
                $table->unsignedInteger('total_prompt_tokens')->default(0);
                $table->unsignedInteger('total_completion_tokens')->default(0);
                $table->decimal('estimated_cost', 10, 6)->nullable();

                $table->json('metadata')->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('voice_turns')) {
            Schema::create('voice_turns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('voice_session_id')->constrained('voice_sessions')->cascadeOnDelete();

                $table->unsignedInteger('sequence')->default(0);
                $table->enum('speaker', ['user', 'assistant']);
                $table->text('transcript')->nullable();

                // Path on the configured private disk, never a public URL.
                $table->string('audio_path')->nullable();
                $table->unsignedInteger('audio_duration_ms')->nullable();

                $table->json('tool_calls')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->enum('status', ['pending', 'completed', 'interrupted', 'failed'])->default('pending');
                $table->text('error_message')->nullable();

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_turns');
        Schema::dropIfExists('voice_sessions');
    }
};
