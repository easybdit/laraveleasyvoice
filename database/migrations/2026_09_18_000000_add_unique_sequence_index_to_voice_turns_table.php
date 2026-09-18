<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defense-in-depth for the sequence-locking fix in
 * VoiceAgent::handleTurn(): even with lockForUpdate() serializing two
 * overlapping requests for the same session on a real row-locking engine,
 * this unique index is what turns any collision that still slips through
 * (a bypassed code path, a non-locking engine) into a clean, catchable
 * database error instead of silently corrupting turn ordering.
 *
 * Additive only - does not touch the original create_voice_tables
 * migration. Assumes no existing voice_turns rows already violate this
 * constraint; an install where the race this fixes has already produced
 * duplicate (voice_session_id, sequence) pairs would need those rows
 * de-duplicated by hand before this migration can apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_turns', function (Blueprint $table) {
            $table->unique(['voice_session_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('voice_turns', function (Blueprint $table) {
            $table->dropUnique(['voice_session_id', 'sequence']);
        });
    }
};
