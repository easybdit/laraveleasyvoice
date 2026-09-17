<?php

namespace EasyAI\LaravelVoice\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Minimal Authenticatable stub for actingAs() in tests - no real users
 * table is needed since the 'auth' middleware only needs the guard's
 * setUser() to have been called, not a database-backed lookup.
 */
class FakeUser implements Authenticatable
{
    public function __construct(protected int $id)
    {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
