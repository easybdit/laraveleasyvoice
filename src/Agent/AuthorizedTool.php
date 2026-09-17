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
 *
 * $tier is metadata only - 'read' (default), 'write', 'destructive', or
 * 'privileged' - looked up via tierOf() and surfaced on
 * ToolCallStarted/ToolCallCompleted for observability/auditing. It does
 * not add a second authorization mechanism on top of Gate; a destructive
 * tool should still gate on its own ability, and if it needs an extra
 * confirmation step, that belongs in the tool's own handler (e.g.
 * requiring a `confirm: true` argument the agent's system prompt
 * instructs the model to only set after the user explicitly confirms).
 *
 * Deliberately still returns a plain Tool (not a subclass) - Tool::make()
 * is a static factory, and PHP requires a static method override to keep
 * a compatible signature, which a Tool subclass adding required
 * constructor arguments cannot do. A WeakMap keyed by the Tool instance
 * carries the tier alongside it instead, with no risk of leaking memory
 * once the Tool itself is no longer referenced anywhere.
 */
final class AuthorizedTool
{
    public const TIER_READ = 'read';

    public const TIER_WRITE = 'write';

    public const TIER_DESTRUCTIVE = 'destructive';

    public const TIER_PRIVILEGED = 'privileged';

    /** @var \WeakMap<Tool, string>|null */
    private static ?\WeakMap $tiers = null;

    public static function make(
        string $name,
        string $description,
        array $parameters,
        string $ability,
        callable $handler,
        mixed $gateArguments = null,
        string $tier = self::TIER_READ,
    ): Tool {
        $tool = Tool::make($name, $description, $parameters, function (array $arguments) use ($ability, $handler, $gateArguments) {
            if (! Gate::allows($ability, $gateArguments)) {
                return ['error' => "Not authorized to perform '{$ability}'."];
            }

            return $handler($arguments);
        });

        self::tiers()[$tool] = $tier;

        return $tool;
    }

    public static function tierOf(Tool $tool): ?string
    {
        return self::tiers()[$tool] ?? null;
    }

    private static function tiers(): \WeakMap
    {
        return self::$tiers ??= new \WeakMap();
    }
}
