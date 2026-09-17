<?php

namespace EasyAI\LaravelVoice\Support;

use EasyAI\LaravelVoice\Models\VoiceSession;
use Illuminate\Container\Container;

/**
 * A tool's handler only ever receives the LLM's parsed arguments
 * (Tool::execute(array $arguments)) - EasyAI's Tool contract has no way
 * to pass extra context through, and is not this package's to change.
 * VoiceAgent::handleTurn() binds the active session into the container
 * for the exact duration of one AI::provider()->run() call (a single
 * synchronous PHP call stack, never concurrent with another turn), so a
 * tool that needs to know "who is calling right now" - like the memory
 * tools - can read it back here instead.
 */
final class CurrentVoiceSession
{
    private const BINDING = 'voice.current_session';

    public static function set(VoiceSession $session): void
    {
        Container::getInstance()->instance(self::BINDING, $session);
    }

    public static function get(): ?VoiceSession
    {
        $container = Container::getInstance();

        return $container->bound(self::BINDING) ? $container->make(self::BINDING) : null;
    }

    public static function clear(): void
    {
        Container::getInstance()->forgetInstance(self::BINDING);
    }
}
