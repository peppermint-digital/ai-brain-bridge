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

        // One-Click-Anbindung: gespeichertes Bundle über die ENV-Defaults legen,
        // BEVOR die Singletons die Config lesen (Spec #249, Phase 1).
        \Peppermint\AiBrainBridge\Config\BridgeConfig::apply();

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
            (string) config('ai-brain-bridge.base_url'),
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

        // Peer-to-Peer-Tabellen (Phase 3) — additiv, im Produkt.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Middleware-Alias, mit dem ein Produkt eigene Endpoints für Peers öffnet.
        $this->app['router']->aliasMiddleware('peer.auth', \Peppermint\AiBrainBridge\Http\Middleware\VerifyPeerToken::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Peppermint\AiBrainBridge\Console\SelftestCommand::class,
                \Peppermint\AiBrainBridge\Console\ConnectCommand::class,
            ]);
        }

        $this->registerInboundRoute();
        $this->registerConnectRoute();
        $this->registerPeerRoutes();
    }

    /**
     * Peer-to-Peer-Routen (Phase 3, Track B) — nur wenn das Produkt sie aktiviert.
     * Öffentlicher claim-Endpoint (Code = Secret) + admin-gegatete Verwaltung.
     */
    protected function registerPeerRoutes(): void
    {
        if (! config('ai-brain-bridge.peer.enabled')) {
            return;
        }

        $controller = \Peppermint\AiBrainBridge\Http\Controllers\PeerConnectController::class;

        // Öffentlich: fremder Code → mein Bundle (gethrottelt, kein Auth).
        Route::middleware((array) config('ai-brain-bridge.peer.claim_middleware', ['api', 'throttle:20,1']))
            ->post((string) config('ai-brain-bridge.peer.claim_route', '/api/v1/connect/claim'), [$controller, 'claim'])
            ->name('peer.connect.claim');

        // Admin: ausstellen / verbinden / liste / widerrufen.
        $prefix = trim((string) config('ai-brain-bridge.peer.admin_prefix', 'admin/peers'), '/');
        Route::middleware((array) config('ai-brain-bridge.peer.admin_middleware', ['web']))
            ->prefix($prefix)
            ->name('peer.admin.')
            ->group(function () use ($controller) {
                Route::get('/', [$controller, 'index'])->name('index');
                Route::post('claim-codes', [$controller, 'issue'])->name('issue');
                Route::post('connect', [$controller, 'connect'])->name('connect');
                Route::delete('{connector}', [$controller, 'revoke'])->name('revoke');
            });
    }

    /**
     * Frontend-agnostische Connect-Route (Phase 2) — nur wenn das Produkt sie
     * aktiviert. Middleware (inkl. Admin-Gate) kommt aus der Produkt-Config.
     */
    protected function registerConnectRoute(): void
    {
        if (! config('ai-brain-bridge.connect.enabled')) {
            return;
        }

        $route = (string) config('ai-brain-bridge.connect.route', '/ai-brain/connect');
        $middleware = (array) config('ai-brain-bridge.connect.middleware', ['web']);
        $controller = \Peppermint\AiBrainBridge\Http\Controllers\ConnectController::class;

        Route::middleware($middleware)->group(function () use ($route, $controller) {
            Route::get($route, [$controller, 'status'])->name('ai-brain-bridge.connect.status');
            Route::post($route, [$controller, 'connect'])->name('ai-brain-bridge.connect');
        });
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
