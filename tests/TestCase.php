<?php

namespace EasyAI\LaravelVoice\Tests;

use EasyAI\LaravelAI\AIServiceProvider;
use EasyAI\LaravelVoice\Facades\Voice;
use EasyAI\LaravelVoice\VoiceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        // AIServiceProvider is listed explicitly (not relying on package
        // auto-discovery) so it's guaranteed to be registered before
        // VoiceServiceProvider regardless of the host test runner's
        // discovery settings.
        return [AIServiceProvider::class, VoiceServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Voice' => Voice::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('ai.default', 'openai');
        $app['config']->set('ai.providers.openai', [
            'driver' => 'openai',
            'api_key' => 'test-key',
            'url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'timeout' => 30,
            'options' => ['temperature' => 0.7, 'max_tokens' => 100],
        ]);

        $app['config']->set('voice.stt.providers.openai', [
            'api_key' => 'test-key',
            'url' => 'https://api.openai.com/v1',
            'model' => 'whisper-1',
            'timeout' => 10,
            'max_file_size' => 25 * 1024 * 1024,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);

        $app['config']->set('voice.tts.providers.openai', [
            'api_key' => 'test-key',
            'url' => 'https://api.openai.com/v1',
            'model' => 'tts-1',
            'voice' => 'alloy',
            'format' => 'mp3',
            'timeout' => 10,
            'max_input_length' => 4096,
            'retries' => 1,
            'retry_sleep_ms' => 0,
        ]);
    }
}
