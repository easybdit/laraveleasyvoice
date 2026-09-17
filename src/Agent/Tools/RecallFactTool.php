<?php

namespace EasyAI\LaravelVoice\Agent\Tools;

use EasyAI\LaravelAI\Agent\Tool;
use EasyAI\LaravelVoice\Agent\AuthorizedTool;
use EasyAI\LaravelVoice\Models\VoiceMemory;
use EasyAI\LaravelVoice\Support\CurrentVoiceSession;

final class RecallFactTool
{
    public static function make(string $ability = 'voice-recall-fact', mixed $gateArguments = null): Tool
    {
        return AuthorizedTool::make(
            name: 'recall_fact',
            description: 'Recall a previously remembered fact about the current caller by its key (see remember_fact).',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string'],
                ],
                'required' => ['key'],
            ],
            ability: $ability,
            handler: function (array $arguments) {
                $session = CurrentVoiceSession::get();

                if ($session === null) {
                    return ['error' => 'No active voice session to recall facts for.'];
                }

                $value = VoiceMemory::recall($session, (string) $arguments['key']);

                return $value !== null ? ['found' => true, 'value' => $value] : ['found' => false];
            },
            gateArguments: $gateArguments,
        );
    }
}
