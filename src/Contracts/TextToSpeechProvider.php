<?php

namespace EasyAI\LaravelVoice\Contracts;

use EasyAI\LaravelVoice\Support\AudioResult;

interface TextToSpeechProvider
{
    /**
     * Synthesize speech audio for the given text. Implementations must
     * reject empty input and enforce their own configured length limit
     * before making any outbound request.
     */
    public function synthesize(string $text, array $options = []): AudioResult;
}
