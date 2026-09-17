<?php

namespace EasyAI\LaravelVoice\Console;

use Illuminate\Console\Command;

/**
 * `php artisan voice:install` - publishes config/migrations, runs
 * migrations, and configures the OpenAI STT/TTS key. Guided and safe to
 * re-run: never overwrites an existing config file, asset, or a real
 * .env value without asking first - same posture as LaravelEasyAI's own
 * `laravelai:install`.
 */
class InstallCommand extends Command
{
    protected $signature = 'voice:install {--force : Overwrite existing published files without confirmation}';

    protected $description = 'Interactively set up LaravelEasyVoice - publishes config/migrations, runs migrations, and configures OpenAI STT/TTS';

    public function handle(): int
    {
        $this->info('🎙️ Setting up LaravelEasyVoice...');
        $this->line('Publishes config/migrations, runs migrations, and configures your first voice provider.');
        $this->newLine();

        $this->publishConfig();
        $this->maybeMigrate();

        $envPairs = $this->configureProvider();
        $envPairs = array_merge($envPairs, $this->configureRoutes());

        if (! $this->writeEnvironment($envPairs)) {
            return 1;
        }

        $this->summary();

        return 0;
    }

    private function publishConfig(): void
    {
        $target = config_path('voice.php');
        $force = (bool) $this->option('force');

        if (file_exists($target) && ! $force) {
            if (! $this->confirm('config/voice.php already exists — overwrite?', false)) {
                $this->line('Skipping config publish (keeping your existing config/voice.php).');

                return;
            }
            $force = true;
        }

        $this->call('vendor:publish', ['--tag' => 'voice-config', '--force' => $force]);
    }

    private function maybeMigrate(): void
    {
        $this->newLine();

        // Every migration in this package guards itself with
        // Schema::hasTable(), so re-running is safe - but some teams
        // manage migrations separately (CI, a deploy step) and wouldn't
        // want an installer running one ad hoc, so ask first.
        if ($this->confirm('Run database migrations now?', true)) {
            $this->call('migrate');

            return;
        }

        $this->line('Skipping migrations — run `php artisan migrate` yourself before using voice sessions.');
    }

    /**
     * OpenAI is the only STT/TTS provider shipped today. If the host app
     * already configured LaravelEasyAI's own OPENAI_API_KEY,
     * config/voice.php already falls back to it - no need to ask twice or
     * store a duplicate key.
     *
     * @return array<string, string>
     */
    private function configureProvider(): array
    {
        $this->newLine();

        if (env('OPENAI_API_KEY')) {
            $this->info('✓ Reusing the existing OPENAI_API_KEY already configured in this app.');

            return [];
        }

        $key = (string) $this->secret('Your OpenAI API key (used for both speech-to-text and text-to-speech)');

        if ($key === '') {
            $this->warn('No key entered — set VOICE_OPENAI_API_KEY (or OPENAI_API_KEY) in .env yourself before using voice sessions.');

            return [];
        }

        return ['VOICE_OPENAI_API_KEY' => $key];
    }

    /**
     * The HTTP API is disabled by default because every request against it
     * triggers billed STT/LLM/TTS calls - asking here is a deliberate
     * moment to explain that, not just a config toggle to click through.
     *
     * @return array<string, string>
     */
    private function configureRoutes(): array
    {
        $this->newLine();

        if ($this->confirm('Enable the built-in HTTP API (POST /voice/sessions, /voice/sessions/{id}/turns, ...)? It runs behind the "auth" middleware by default.', false)) {
            return ['VOICE_ROUTES_ENABLED' => 'true'];
        }

        $this->line('Skipping — you can call Voice::agent(...) directly from your own controllers, or enable this later with VOICE_ROUTES_ENABLED=true.');

        return [];
    }

    /**
     * @param  array<string, string>  $envPairs
     */
    private function writeEnvironment(array $envPairs): bool
    {
        if ($envPairs === []) {
            return true;
        }

        $this->newLine();
        $this->info('Writing configuration to .env...');

        try {
            foreach ($envPairs as $key => $value) {
                $this->setEnvKey($key, $value);
            }
        } catch (\Throwable $e) {
            $this->error('Could not write to .env: '.$e->getMessage());
            $this->line('Add these lines to your .env manually:');
            foreach ($envPairs as $key => $value) {
                $this->line("  {$key}={$value}");
            }

            return false;
        }

        return true;
    }

    /**
     * Append a key to the host app's .env - never overwrite a key that
     * already has a real value, only fill in truly-empty ones or append
     * genuinely new ones. Never corrupt an existing working .env.
     */
    private function setEnvKey(string $key, string $value): void
    {
        $envPath = base_path('.env');

        $contents = file_exists($envPath) ? (file_get_contents($envPath) ?: '') : '';
        if ($contents === '' && ! file_exists($envPath) && ! is_dir(dirname($envPath))) {
            throw new \RuntimeException('Directory for .env does not exist: '.dirname($envPath));
        }

        $formattedValue = $this->formatEnvValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';

        if (preg_match($pattern, $contents, $matches)) {
            $existing = trim($matches[1]);

            if ($existing !== '') {
                $this->warn("{$key} already has a value in .env — leaving it untouched.");

                return;
            }

            $contents = preg_replace($pattern, $key.'='.$formattedValue, $contents, 1);
        } else {
            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }
            $contents .= $key.'='.$formattedValue."\n";
        }

        if (file_put_contents($envPath, $contents) === false) {
            throw new \RuntimeException("Failed to write {$key} to .env");
        }
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#|"/', $value)) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }

    private function summary(): void
    {
        $this->newLine();
        $this->info('✅ LaravelEasyVoice is set up!');
        $this->newLine();
        $this->line('Register your first agent, typically in a service provider\'s boot():');
        $this->line('');
        $this->line("  Voice::registerAgent('receptionist', function (\$agent) {");
        $this->line("      \$agent->stt('openai')->tts('openai')->llm('openai')");
        $this->line("          ->systemPrompt('You are a friendly front-desk receptionist.');");
        $this->line('  });');
        $this->line('');
        $this->line('Then: Voice::agent(\'receptionist\')->startSession(...)->handleTurn(...)');
    }
}
