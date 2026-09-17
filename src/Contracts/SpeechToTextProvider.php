<?php

namespace EasyAI\LaravelVoice\Contracts;

use EasyAI\LaravelVoice\Support\TranscriptionResult;

interface SpeechToTextProvider
{
    /**
     * Transcribe a local audio file. Implementations must validate the
     * file exists and enforce their own configured size limit before
     * making any outbound request.
     */
    public function transcribe(string $audioFilePath, array $options = []): TranscriptionResult;
}
