<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client-supplied, per-attempt identifier (sent as the optional
 * Idempotency-Key HTTP header, or $options['idempotency_key'] on
 * VoiceAgent::handleTurn() directly), stored on the user turn of a pair -
 * the first row a turn attempt creates, before STT/LLM/TTS run. See
 * handleTurn()/claimNextTurn()/resolveDuplicateTurn() for how a repeated
 * key is detected and resolved without re-running any provider call.
 *

 * Length 191, not the default 255 - the conventional safe ceiling for an
 * indexed string column on older MySQL/InnoDB configurations without
 * innodb_large_prefix (utf8mb4's 4 bytes/char × 191 stays under the
 * 767-byte index-prefix limit); every other indexed string column in
 * this package's own migrations (guest_token, provider_*, voice,
 * language, channel) already follows this same deliberate-length
 * convention rather than the bare default.
 *
 * Nullable and additive only - a NULL never collides with another NULL
 * under a UNIQUE index on any of MySQL/MariaDB/SQLite/PostgreSQL, so a
 * caller that never sends a key is completely unaffected; existing
 * no-key behavior is preserved exactly. Does not touch the original
 * create_voice_tables migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_turns', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->after('sequence');
            $table->unique(['voice_session_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('voice_turns', function (Blueprint $table) {
            $table->dropUnique(['voice_session_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
