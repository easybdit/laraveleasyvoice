<?php

namespace EasyAI\LaravelVoice\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for a real, repeated mistake made while dogfooding
 * this package against a live host app: 'auth' was hardcoded into
 * voice.routes.middleware, so temporarily removing it for local guest
 * testing meant hand-editing the published config/voice.php - an edit
 * that silently vanished (and broke guest access again, more than once)
 * every time `vendor:publish --tag=voice-config --force` ran. Fixed by
 * computing 'auth' from an env var instead, so the toggle lives in
 * .env and survives republishing. This test exercises the raw config
 * file directly (no Laravel app needed) since that's exactly the layer
 * the bug was in.
 */
class VoiceRoutesConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('VOICE_ROUTES_REQUIRE_AUTH');
        unset($_ENV['VOICE_ROUTES_REQUIRE_AUTH'], $_SERVER['VOICE_ROUTES_REQUIRE_AUTH']);

        parent::tearDown();
    }

    public function test_auth_is_included_by_default(): void
    {
        putenv('VOICE_ROUTES_REQUIRE_AUTH');
        unset($_ENV['VOICE_ROUTES_REQUIRE_AUTH'], $_SERVER['VOICE_ROUTES_REQUIRE_AUTH']);

        $config = require __DIR__.'/../../config/voice.php';

        $this->assertSame(['web', 'auth'], $config['routes']['middleware']);
    }

    public function test_auth_is_omitted_when_require_auth_is_explicitly_false(): void
    {
        putenv('VOICE_ROUTES_REQUIRE_AUTH=false');
        $_ENV['VOICE_ROUTES_REQUIRE_AUTH'] = 'false';

        $config = require __DIR__.'/../../config/voice.php';

        $this->assertSame(['web'], $config['routes']['middleware']);
    }
}
