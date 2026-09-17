<?php

namespace EasyAI\LaravelVoice\Support;

final class TranscriptionResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $language = null,
        public readonly ?float $durationSeconds = null,
        public readonly array $raw = [],
    ) {
    }
}
