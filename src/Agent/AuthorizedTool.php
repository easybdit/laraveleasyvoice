<?php

namespace EasyAI\LaravelVoice\Agent;

use EasyAI\LaravelAI\Agent\Tool;
use Illuminate\Support\Facades\Gate;

/**
 * Wraps an EasyAI Tool so it can never execute without an explicit
 * authorization check. A voice agent takes spoken, untrusted input and
 * turns it into function calls - every tool exposed to it must declare
 * who is allowed to trigger it, checked at call time, not just at
 * registration time.
 */
final class AuthorizedTool
{
    public static function make(
        string $name,
        string $description,
        array $parameters,
        string $ability,
        callable $handler,
        mixed $gateArguments = null,
    ): Tool {
        return Tool::make($name, $description, $parameters, function (array $arguments) use ($ability, $handler, $gateArguments) {
            if (! Gate::allows($ability, $gateArguments)) {
                return ['error' => "Not authorized to perform '{$ability}'."];
            }

            return $handler($arguments);
        });
    }
}
