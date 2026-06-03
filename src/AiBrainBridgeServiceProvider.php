<?php

namespace Peppermint\AiBrainBridge;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Http\Controllers\InboundEventController;
use Peppermint\AiBrainBridge\Http\Middleware\VerifyAiBrainSignature;

class AiBrainBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-brain-bridge.php', 'ai-brain-bridge');

        $this->app->singleton(OAuthTokenProvider::class, fn () => new OAuthTokenProvider(
            array_merge(
                (array) config('ai-brain-bridge.oauth'),
                ['base_url' => config('ai-brain-bridge.base_url')],
            ),
        ));

        $this->app->singleton(EventPublisher::class, fn ($app) => new EventPublisher(
            (array) config('ai-brain-bridge.events'),
            $app->make(OAuthTokenProvider::class),
            (string) config('ai-brain-bridge.source'),
        ));

        $this->app->singleton(AiBrainManager::class, fn ($app) => new AiBrainManager(
            (array) config('ai-brain-bridge'),
            $app->make(OAuthTokenProvider::class),
            $app->make(EventPublisher::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-brain-bridge.php' => config_path('ai-brain-bridge.php'),
        ], 'ai-brain-bridge-config');

        $this->registerInboundRoute();
    }

    protected function registerInboundRoute(): void
    {
        $middleware = array_merge(
            (array) config('ai-brain-bridge.inbound.middleware', ['api']),
            [VerifyAiBrainSignature::class],
        );

        Route::middleware($middleware)
            ->post(config('ai-brain-bridge.inbound.route', '/webhooks/ai-brain'), InboundEventController::class)
            ->name('ai-brain-bridge.inbound');
    }
}
