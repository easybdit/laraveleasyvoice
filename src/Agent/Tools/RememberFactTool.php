<?php

namespace EasyAI\LaravelVoice\Agent\Tools;

use EasyAI\LaravelAI\Agent\Tool;
use EasyAI\LaravelVoice\Agent\AuthorizedTool;
use EasyAI\LaravelVoice\Models\VoiceMemory;
use EasyAI\LaravelVoice\Support\CurrentVoiceSession;

/**
 * Ready-made tool a registered agent can opt into for cross-session
 * memory ("My name is Murad" now, "What's my name?" next week) -
 * deliberately a flat key/value fact store, not a general memory system.
 * The system prompt has to actually instruct the model when to call
 * this (e.g. "call remember_fact whenever the caller shares their name
 * or a preference") - nothing captures facts automatically.
 */
final class RememberFactTool
{
    public static function make(string $ability = 'voice-remember-fact', mixed $gateArguments = null): Tool
    {
        return AuthorizedTool::make(
            name: 'remember_fact',
            description: 'Remember a fact about the current caller for future calls, e.g. their name or a stated preference. Use a short, consistent key (e.g. "name").',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'A short identifier for the fact, e.g. "name" or "preferred_language".'],
                    'value' => ['type' => 'string'],
                ],
                'required' => ['key', 'value'],
            ],
            ability: $ability,
            handler: function (array $arguments) {
                $session = CurrentVoiceSession::get();

                if ($session === null) {
                    return ['error' => 'No active voice session to remember this against.'];
                }

                if (! VoiceMemory::hasIdentity($session)) {
                    return ['error' => 'This caller has no identity to remember facts against.'];
                }

                VoiceMemory::remember($session, (string) $arguments['key'], (string) $arguments['value']);

                return ['remembered' => true];
            },
            gateArguments: $gateArguments,
        );
    }
}
