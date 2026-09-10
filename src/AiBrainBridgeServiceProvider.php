<?php

namespace Peppermint\AiBrainBridge;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Config\BridgeConfig;
use Peppermint\AiBrainBridge\Console\ConnectCommand;
use Peppermint\AiBrainBridge\Console\PushHealthCommand;
use Peppermint\AiBrainBridge\Console\SelftestCommand;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Health\ExceptionRecorder;
use Peppermint\AiBrainBridge\Health\HealthReporting;
use Peppermint\AiBrainBridge\Health\SlowQueryRecorder;
use Peppermint\AiBrainBridge\Http\Controllers\BrainLoginController;
use Peppermint\AiBrainBridge\Http\Controllers\ConnectController;
use Peppermint\AiBrainBridge\Http\Controllers\InboundEventController;
use Peppermint\AiBrainBridge\Http\Controllers\PeerConnectController;
use Peppermint\AiBrainBridge\Http\Controllers\SwitcherController;
use Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser;
use Peppermint\AiBrainBridge\Http\Middleware\ResolvePeerActingUser;
use Peppermint\AiBrainBridge\Http\Middleware\VerifyAiBrainSignature;
use Peppermint\AiBrainBridge\Http\Middleware\VerifyPeerToken;
use Peppermint\AiBrainBridge\Peer\DefaultPeerTokenIssuer;
use Peppermint\AiBrainBridge\Peer\PeerTokenIssuer;

class AiBrainBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-brain-bridge.php', 'ai-brain-bridge');

        // One-Click-Anbindung: gespeichertes Bundle über die ENV-Defaults legen,
        // BEVOR die Singletons die Config lesen (Spec #249, Phase 1).
        BridgeConfig::apply();

        // Peer-Token-Aussteller (Phase 3b) — Default = SDK-Token. Ein Produkt
        // überschreibt dieses Binding mit einem nativen api.token-Issuer.
        $this->app->bind(
            PeerTokenIssuer::class,
            DefaultPeerTokenIssuer::class,
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
        $this->app['router']->aliasMiddleware('peer.auth', VerifyPeerToken::class);

        // Eingehende Acting-User-Delegation (#471): hinter die MCP-Auth des
        // Produkts hängen, dann handelt der MCP-Aufruf als der Mensch, der die
        // Nachricht geschrieben hat — statt als Token-Besitzer.
        $this->app['router']->aliasMiddleware('ai-brain.acting-user', ResolveAiBrainActingUser::class);

        // Dasselbe zwischen zwei Produkten (#3459): hinter die API-Auth hängen,
        // dann trägt ein Peer-Aufruf den Menschen, der ihn ausgelöst hat, statt
        // gar niemanden. Persönliche Tokens bleiben unberührt.
        $this->app['router']->aliasMiddleware('peer.acting-user', ResolvePeerActingUser::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SelftestCommand::class,
                ConnectCommand::class,
                PushHealthCommand::class,
            ]);
        }

        $this->registerInboundRoute();
        $this->registerConnectRoute();
        $this->registerLoginRoutes();
        $this->registerSwitcherRoutes();
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
        if (! HealthReporting::aktiv()) {
            return;
        }

        $this->app->singleton(ExceptionRecorder::class);
        $this->app->singleton(SlowQueryRecorder::class);

        // Bewusst auch in der Konsole: Queue-Worker sind der Ort, an dem die
        // interessanten Fehler passieren.
        if (config('ai-brain-bridge.health.exceptions.enabled', true)) {
            Event::listen(
                MessageLogged::class,
                fn ($event) => $this->app->make(ExceptionRecorder::class)->record($event),
            );
        }

        if (config('ai-brain-bridge.health.slow_queries.enabled', true)) {
            Event::listen(
                QueryExecuted::class,
                fn ($event) => $this->app->make(SlowQueryRecorder::class)->record($event),
            );
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
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
                [PeerConnectController::class, 'claim'],
            )
            ->name('peer.connect.claim');

        // Übergabe des Signatur-Geheimnisses für Verbindungen, die vor #540
        // entstanden sind. Hinter `peer.auth` — der Peer-Token dieser Verbindung
        // ist der Ausweis; ohne ihn kommt hier niemand an.
        Route::middleware(['api', 'peer.auth', 'throttle:20,1'])
            ->post(
                '/api/v1/peer/acting-secret',
                [PeerConnectController::class, 'actingSecret'],
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
        $controller = ConnectController::class;

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

        $controller = BrainLoginController::class;
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

    /**
     * Umschaltleiste zwischen den Peppermint-Systemen (AI Brain #5281).
     *
     * Haengt an ZWEI Schaltern: an ihrem eigenen und am gemeinsamen Anmeldeweg.
     * Ohne den zweiten fuehrt jeder Knopf, dessen Ziel keine Sitzung hat, auf
     * eine Anmeldemaske — also genau dorthin, wo die Leiste hinwegfuehren soll.
     */
    protected function registerSwitcherRoutes(): void
    {
        // IMMER registrieren, auch abgeschaltet: Eine Blade-Direktive, die es
        // nicht gibt, wird nicht uebersprungen — Blade laesst `@aiBrainSwitcher`
        // woertlich stehen und schreibt sie auf jede Seite des Produkts. Der
        // Schalter gehoert deshalb in die AUSGABE, nicht in die Registrierung.
        $this->registerSwitcherDirective();

        if (! config('ai-brain-bridge.switcher.enabled') || ! config('ai-brain-bridge.login.enabled')) {
            return;
        }

        $controller = SwitcherController::class;
        $middleware = (array) config('ai-brain-bridge.switcher.middleware', ['web']);

        Route::middleware($middleware)->group(function () use ($controller): void {
            Route::get(
                (string) config('ai-brain-bridge.switcher.go_path', '/auth/brain/go'),
                [$controller, 'go'],
            )->name('ai-brain-bridge.switcher.go');

            Route::get(
                (string) config('ai-brain-bridge.switcher.apps_path', '/ai-brain/switcher/apps'),
                [$controller, 'apps'],
            )->name('ai-brain-bridge.switcher.apps');

            Route::get(
                (string) config('ai-brain-bridge.switcher.script_path', '/ai-brain/switcher/app-switcher.js'),
                [$controller, 'script'],
            )->name('ai-brain-bridge.switcher.script');
        });
    }

    /**
     * `@aiBrainSwitcher` — eine Zeile im Layout des Produkts.
     *
     * Bewusst eine Blade-Direktive und kein Vue-/React-Bauteil: Der Manager
     * laeuft auf Vue, CRM und Verwaltung auf React. Im Layout haengt sie in
     * allen dreien gleich.
     */
    protected function registerSwitcherDirective(): void
    {
        Blade::directive(
            'aiBrainSwitcher',
            fn (): string => "<?php echo app('".SwitcherController::class."')->markup(); ?>",
        );
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
