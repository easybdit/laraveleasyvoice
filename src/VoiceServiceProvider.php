<?php

namespace EasyAI\LaravelVoice;

use EasyAI\LaravelVoice\Console\InstallCommand;
use EasyAI\LaravelVoice\Managers\SpeechToTextManager;
use EasyAI\LaravelVoice\Managers\TextToSpeechManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class VoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/voice.php', 'voice');

        $this->app->singleton(SpeechToTextManager::class, fn ($app) => new SpeechToTextManager($app));
        $this->app->singleton(TextToSpeechManager::class, fn ($app) => new TextToSpeechManager($app));

        $this->app->singleton('laravel-voice', fn ($app) => new VoiceManager(
            $app->make(SpeechToTextManager::class),
            $app->make(TextToSpeechManager::class),
        ));

        $this->app->alias('laravel-voice', VoiceManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $middleware = array_merge(
            config('voice.routes.middleware', ['web', 'auth']),
            ['throttle:'.config('voice.routes.throttle', '30,1')],
        );

        if (config('voice.routes.enabled', false)) {
            Route::middleware($middleware)->group(__DIR__.'/../routes/voice.php');
        }

        // Independent toggle from voice.routes.enabled - a host app may
        // want the realtime token endpoint without the full turn-based
        // session/turn API, or vice versa.
        if (config('voice.realtime.enabled', false)) {
            Route::middleware($middleware)->group(__DIR__.'/../routes/voice-realtime.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/voice.php' => config_path('voice.php'),
            ], 'voice-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'voice-migrations');

            $this->publishes([
                __DIR__.'/../resources/js/voice-widget.js' => public_path('vendor/laraveleasyvoice/voice-widget.js'),
                __DIR__.'/../resources/js/voice-realtime.js' => public_path('vendor/laraveleasyvoice/voice-realtime.js'),
                __DIR__.'/../resources/js/voice-realtime-deepgram.js' => public_path('vendor/laraveleasyvoice/voice-realtime-deepgram.js'),
            ], 'voice-assets');

            $this->commands([InstallCommand::class]);
        }
    }
}
