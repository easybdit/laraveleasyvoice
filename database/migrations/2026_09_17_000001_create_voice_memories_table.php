<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deliberately minimal per-caller key-value store - "My name is
 * Murad" now / "What's my name?" next week - not a general-purpose
 * memory/retrieval system. No embeddings, no staleness/decay, no
 * automatic capture: a tool has to explicitly call remember_fact for
 * anything to end up here. Same nullable, FK-less identity columns as
 * voice_sessions, for the same reason - this package cannot assume the
 * host app's user/tenant model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_memories')) {
            Schema::create('voice_memories', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('guest_token', 64)->nullable()->index();

                $table->string('key', 100);
                $table->text('value');

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_memories');
    }
};
