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

        // Peer-Token-Aussteller (Phase 3b) — Default = SDK-Token. Ein Produkt
        // überschreibt dieses Binding mit einem nativen api.token-Issuer.
        $this->app->bind(
            \Peppermint\AiBrainBridge\Peer\PeerTokenIssuer::class,
            \Peppermint\AiBrainBridge\Peer\DefaultPeerTokenIssuer::class,
        );

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

        // Eingehende Acting-User-Delegation (#471): hinter die MCP-Auth des
        // Produkts hängen, dann handelt der MCP-Aufruf als der Mensch, der die
        // Nachricht geschrieben hat — statt als Token-Besitzer.
        $this->app['router']->aliasMiddleware('ai-brain.acting-user', \Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser::class);

        // Dasselbe zwischen zwei Produkten (#3459): hinter die API-Auth hängen,
        // dann trägt ein Peer-Aufruf den Menschen, der ihn ausgelöst hat, statt
        // gar niemanden. Persönliche Tokens bleiben unberührt.
        $this->app['router']->aliasMiddleware('peer.acting-user', \Peppermint\AiBrainBridge\Http\Middleware\ResolvePeerActingUser::class);

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
     * Peer-to-Peer (Phase 3, Track B) — nur wenn das Produkt es aktiviert. Das SDK
     * registriert AUSSCHLIESSLICH den öffentlichen claim-Endpoint (Code = Secret,
     * gethrottelt, kein Auth). Die ADMIN-Aktionen (ausstellen/verbinden/widerrufen)
     * baut jedes Produkt SELBST hinter seinem eigenen Admin-Gate — über den
     * `PeerConnectionManager`-Service. So gibt es keinen unsicheren Default.
     */
    protected function registerPeerRoutes(): void
    {
        if (! config('ai-brain-bridge.peer.enabled')) {
            return;
        }

        Route::middleware((array) config('ai-brain-bridge.peer.claim_middleware', ['api', 'throttle:20,1']))
            ->post(
                (string) config('ai-brain-bridge.peer.claim_route', '/api/v1/connect/claim'),
                [\Peppermint\AiBrainBridge\Http\Controllers\PeerConnectController::class, 'claim'],
            )
            ->name('peer.connect.claim');

        // Übergabe des Signatur-Geheimnisses für Verbindungen, die vor #540
        // entstanden sind. Hinter `peer.auth` — der Peer-Token dieser Verbindung
        // ist der Ausweis; ohne ihn kommt hier niemand an.
        Route::middleware(['api', 'peer.auth', 'throttle:20,1'])
            ->post(
                '/api/v1/peer/acting-secret',
                [\Peppermint\AiBrainBridge\Http\Controllers\PeerConnectController::class, 'actingSecret'],
            )
            ->name('peer.acting-secret');
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
