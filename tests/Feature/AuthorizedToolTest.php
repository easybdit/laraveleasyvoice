<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Agent\AuthorizedTool;
use EasyAI\LaravelVoice\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

class AuthorizedToolTest extends TestCase
{
    public function test_it_executes_the_handler_when_authorized(): void
    {
        // Gate closures take a $user param (nullable/defaulted) even in a
        // guest context — Laravel's Gate refuses to invoke a zero-arg
        // closure for an unauthenticated user, treating it as guest-unsafe.
        Gate::define('view-attendance', fn ($user = null) => true);

        $tool = AuthorizedTool::make(
            name: 'check_attendance',
            description: "Check today's attendance",
            parameters: ['type' => 'object', 'properties' => []],
            ability: 'view-attendance',
            handler: fn (array $args) => ['present' => 42],
        );

        $result = $tool->execute([]);

        $this->assertSame(['present' => 42], $result);
    }

    public function test_it_blocks_the_handler_when_not_authorized(): void
    {
        Gate::define('delete-student', fn ($user = null) => false);

        $tool = AuthorizedTool::make(
            name: 'delete_student',
            description: 'Delete a student record',
            parameters: ['type' => 'object', 'properties' => []],
            ability: 'delete-student',
            handler: fn (array $args) => ['deleted' => true],
        );

        $result = $tool->execute([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('deleted', $result);
    }
}
