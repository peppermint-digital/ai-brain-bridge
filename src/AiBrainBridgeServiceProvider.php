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
                \Peppermint\AiBrainBridge\Console\PushHealthCommand::class,
            ]);
        }

        $this->registerInboundRoute();
        $this->registerConnectRoute();
        $this->registerLoginRoutes();
        $this->registerPeerRoutes();
        $this->registerHealthReporting();
    }

    /**
     * App-Health melden (K7, AI Brain #5239).
     *
     * Kam aus dem eigenstaendigen Paket `ai-brain/laravel-connector`. Ein
     * Produkt soll ein Paket installieren und einen Konfigurationsblock
     * pflegen, nicht zwei mit derselben Gegenstelle.
     *
     * ## Der Ruecktritt
     *
     * Solange das alte Paket noch installiert ist, tut dieser Weg NICHTS. Sonst
     * liefe die Meldung waehrend der Umstellung doppelt — und zwei Meldungen
     * derselben App im Fuenf-Minuten-Takt sehen in AI Brain aus wie ein
     * flatternder Dienst, nicht wie ein Umbau. Die Produkte koennen das alte
     * Paket damit in Ruhe entfernen, jedes zu seinem eigenen Deploy.
     */
    protected function registerHealthReporting(): void
    {
        if (! \Peppermint\AiBrainBridge\Health\HealthReporting::aktiv()) {
            return;
        }

        $this->app->singleton(\Peppermint\AiBrainBridge\Health\ExceptionRecorder::class);
        $this->app->singleton(\Peppermint\AiBrainBridge\Health\SlowQueryRecorder::class);

        // Bewusst auch in der Konsole: Queue-Worker sind der Ort, an dem die
        // interessanten Fehler passieren.
        if (config('ai-brain-bridge.health.exceptions.enabled', true)) {
            \Illuminate\Support\Facades\Event::listen(
                \Illuminate\Log\Events\MessageLogged::class,
                fn ($event) => $this->app->make(\Peppermint\AiBrainBridge\Health\ExceptionRecorder::class)->record($event),
            );
        }

        if (config('ai-brain-bridge.health.slow_queries.enabled', true)) {
            \Illuminate\Support\Facades\Event::listen(
                \Illuminate\Database\Events\QueryExecuted::class,
                fn ($event) => $this->app->make(\Peppermint\AiBrainBridge\Health\SlowQueryRecorder::class)->record($event),
            );
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $event = $schedule->command('ai-brain:push-health')->withoutOverlapping();

            $takt = (string) config('ai-brain-bridge.health.schedule', 'everyFiveMinutes');

            method_exists($event, $takt) ? $event->{$takt}() : $event->everyFiveMinutes();
        });
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

    /**
     * „Mit AI Brain anmelden" (AI Brain #5266) — nur wenn das Produkt es
     * einschaltet. Ohne Freischaltung existieren die Routen nicht; der
     * gewohnte Anmeldeweg ist davon in keinem Fall betroffen.
     */
    protected function registerLoginRoutes(): void
    {
        if (! config('ai-brain-bridge.login.enabled')) {
            return;
        }

        $controller = \Peppermint\AiBrainBridge\Http\Controllers\BrainLoginController::class;
        $middleware = (array) config('ai-brain-bridge.login.middleware', ['web']);

        Route::middleware($middleware)->group(function () use ($controller): void {
            Route::get(
                (string) config('ai-brain-bridge.login.redirect_path', '/auth/brain/redirect'),
                [$controller, 'redirect'],
            )->name('ai-brain-bridge.login.redirect');

            Route::get(
                (string) config('ai-brain-bridge.login.callback_path', '/auth/brain/callback'),
                [$controller, 'callback'],
            )->name('ai-brain-bridge.login.callback');
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
