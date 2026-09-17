<?php

namespace EasyAI\LaravelVoice\Support;

final class AudioResult
{
    public function __construct(
        public readonly string $binary,
        public readonly string $mimeType,
        public readonly ?float $durationSeconds = null,
        public readonly array $raw = [],
    ) {
    }
}
