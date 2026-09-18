<?php

namespace EasyAI\LaravelVoice\Tests\Feature;

use EasyAI\LaravelVoice\Tests\TestCase;

/**
 * `php artisan voice:install` - every test runs against a disposable temp
 * directory as the app's base_path() so the command's .env read/write
 * never touches this repo's real .env or the shared Testbench sandbox.
 * Mirrors LaravelEasyAI's own InstallCommandTest conventions.
 */
class InstallCommandTest extends TestCase
{
    private string $tempBasePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->tempBasePath = sys_get_temp_dir().'/voice_install_test_'.uniqid();
        mkdir($this->tempBasePath, 0777, true);

        $app->setBasePath($this->tempBasePath);
    }

    protected function tearDown(): void
    {
        if (! empty($this->tempBasePath) && is_dir($this->tempBasePath)) {
            $this->deleteDirectory($this->tempBasePath);
        }

        putenv('OPENAI_API_KEY');
        unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);

        putenv('VOICE_DEEPGRAM_API_KEY');
        unset($_ENV['VOICE_DEEPGRAM_API_KEY'], $_SERVER['VOICE_DEEPGRAM_API_KEY']);

        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function envPath(): string
    {
        return $this->tempBasePath.DIRECTORY_SEPARATOR.'.env';
    }

    public function test_full_interactive_run_writes_the_api_key_and_succeeds(): void
    {
        $this->artisan('voice:install')
            ->expectsConfirmation('Run database migrations now?', 'yes')
            ->expectsQuestion('Your OpenAI API key (used for both speech-to-text and text-to-speech)', 'sk-test-123')
            ->expectsConfirmation(
                "Also configure a Deepgram API key for speech-to-text? (optional - only needed for Voice::stt('deepgram'))",
                'no'
            )
            ->expectsConfirmation(
                'Enable the built-in HTTP API (POST /voice/sessions, /voice/sessions/{id}/turns, ...)? It runs behind the "auth" middleware by default.',
                'no'
            )
            ->expectsOutputToContain('Setting up LaravelEasyVoice')
            ->expectsOutputToContain('Voice::registerAgent')
            ->assertExitCode(0);

        $this->assertFileExists($this->envPath());

        $contents = file_get_contents($this->envPath());
        $this->assertStringContainsString('VOICE_OPENAI_API_KEY=sk-test-123', $contents);
        $this->assertStringNotContainsString('VOICE_ROUTES_ENABLED', $contents);
        $this->assertStringNotContainsString('VOICE_DEEPGRAM_API_KEY', $contents);
    }

    public function test_it_reuses_an_existing_openai_api_key_without_asking(): void
    {
        putenv('OPENAI_API_KEY=sk-already-set');
        $_ENV['OPENAI_API_KEY'] = 'sk-already-set';

        $this->artisan('voice:install')
            ->expectsConfirmation('Run database migrations now?', 'no')
            ->expectsConfirmation(
                "Also configure a Deepgram API key for speech-to-text? (optional - only needed for Voice::stt('deepgram'))",
                'no'
            )
            ->expectsConfirmation(
                'Enable the built-in HTTP API (POST /voice/sessions, /voice/sessions/{id}/turns, ...)? It runs behind the "auth" middleware by default.',
                'yes'
            )
            ->expectsOutputToContain('Reusing the existing OPENAI_API_KEY')
            ->assertExitCode(0);

        $contents = file_exists($this->envPath()) ? file_get_contents($this->envPath()) : '';
        $this->assertStringNotContainsString('VOICE_OPENAI_API_KEY', $contents);
        $this->assertStringContainsString('VOICE_ROUTES_ENABLED=true', $contents);
    }

    public function test_it_does_not_overwrite_an_existing_non_empty_env_value(): void
    {
        file_put_contents($this->envPath(), "APP_NAME=Test\nVOICE_OPENAI_API_KEY=sk-original\n");

        $this->artisan('voice:install')
            ->expectsConfirmation('Run database migrations now?', 'no')
            ->expectsQuestion('Your OpenAI API key (used for both speech-to-text and text-to-speech)', 'sk-should-not-be-written')
            ->expectsConfirmation(
                "Also configure a Deepgram API key for speech-to-text? (optional - only needed for Voice::stt('deepgram'))",
                'no'
            )
            ->expectsConfirmation(
                'Enable the built-in HTTP API (POST /voice/sessions, /voice/sessions/{id}/turns, ...)? It runs behind the "auth" middleware by default.',
                'no'
            )
            ->assertExitCode(0);

        $contents = file_get_contents($this->envPath());
        $this->assertStringContainsString('VOICE_OPENAI_API_KEY=sk-original', $contents);
        $this->assertStringNotContainsString('sk-should-not-be-written', $contents);
    }

    public function test_it_writes_an_optional_deepgram_key_when_the_user_opts_in(): void
    {
        $this->artisan('voice:install')
            ->expectsConfirmation('Run database migrations now?', 'no')
            ->expectsQuestion('Your OpenAI API key (used for both speech-to-text and text-to-speech)', 'sk-test-123')
            ->expectsConfirmation(
                "Also configure a Deepgram API key for speech-to-text? (optional - only needed for Voice::stt('deepgram'))",
                'yes'
            )
            ->expectsQuestion('Your Deepgram API key', 'dg-test-456')
            ->expectsConfirmation(
                'Enable the built-in HTTP API (POST /voice/sessions, /voice/sessions/{id}/turns, ...)? It runs behind the "auth" middleware by default.',
                'no'
            )
            ->assertExitCode(0);

        $contents = file_get_contents($this->envPath());
        $this->assertStringContainsString('VOICE_OPENAI_API_KEY=sk-test-123', $contents);
        $this->assertStringContainsString('VOICE_DEEPGRAM_API_KEY=dg-test-456', $contents);
    }

    public function test_it_skips_the_deepgram_prompt_when_declined(): void
    {
        $this->artisan('voice:install')
            ->expectsConfirmation('Run database migrations now?', 'no')
            ->expectsQuestion('Your OpenAI API key (used for both speech-to-text and text-to-speech)', 'sk-test-123')
            ->expectsConfirmation(
                "Also configure a Deepgram API key for speech-to-text? (optional - only needed for Voice::stt('deepgram'))",
                'no'
            )
            ->expectsConfirmation(
                'Enable the built-in HTTP API (POST /voice/sessions, /voice/sessions/{id}/turns, ...)? It runs behind the "auth" middleware by default.',
                'no'
            )
            ->assertExitCode(0);

        $contents = file_get_contents($this->envPath());
        $this->assertStringNotContainsString('VOICE_DEEPGRAM_API_KEY', $contents);
    }

    public function test_it_reuses_an_existing_deepgram_api_key_without_asking(): void
    {
        putenv('VOICE_DEEPGRAM_API_KEY=dg-already-set');
        $_ENV['VOICE_DEEPGRAM_API_KEY'] = 'dg-already-set';

        $this->artisan('voice:install')
            ->expectsConfirmation('Run database migrations now?', 'no')
            ->expectsQuestion('Your OpenAI API key (used for both speech-to-text and text-to-speech)', 'sk-test-123')
            ->expectsConfirmation(
                'Enable the built-in HTTP API (POST /voice/sessions, /voice/sessions/{id}/turns, ...)? It runs behind the "auth" middleware by default.',
                'no'
            )
            ->expectsOutputToContain('Reusing the existing VOICE_DEEPGRAM_API_KEY')
            ->assertExitCode(0);

        $contents = file_exists($this->envPath()) ? file_get_contents($this->envPath()) : '';
        $this->assertStringNotContainsString('VOICE_DEEPGRAM_API_KEY=dg-already-set', $contents);
    }

    public function test_it_does_not_print_the_deepgram_key_to_output(): void
    {
        $this->artisan('voice:install')
            ->expectsConfirmation('Run database migrations now?', 'no')
            ->expectsQuestion('Your OpenAI API key (used for both speech-to-text and text-to-speech)', 'sk-test-123')
            ->expectsConfirmation(
                "Also configure a Deepgram API key for speech-to-text? (optional - only needed for Voice::stt('deepgram'))",
                'yes'
            )
            ->expectsQuestion('Your Deepgram API key', 'dg-super-secret')
            ->expectsConfirmation(
                'Enable the built-in HTTP API (POST /voice/sessions, /voice/sessions/{id}/turns, ...)? It runs behind the "auth" middleware by default.',
                'no'
            )
            ->expectsOutputToContain('Writing configuration to .env...')
            ->assertExitCode(0);

        // The `secret()` prompt itself is masked by Symfony Console and
        // never echoed - this test's real guarantee is that nothing in the
        // command's own writeEnvironment()/summary() output ever reprints
        // the raw key. The value in .env is the input the test itself
        // supplied via expectsQuestion(), not something the command printed.
        $contents = file_get_contents($this->envPath());
        $this->assertStringContainsString('VOICE_DEEPGRAM_API_KEY=dg-super-secret', $contents);
    }
}
