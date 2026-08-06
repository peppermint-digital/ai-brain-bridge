<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser;
use Peppermint\AiBrainBridge\Http\Middleware\ResolvePeerActingUser;
use Peppermint\AiBrainBridge\Peer\PeerClaimCode;
use Peppermint\AiBrainBridge\Peer\PeerClient;
use Peppermint\AiBrainBridge\Peer\PeerConnectionManager;
use Peppermint\AiBrainBridge\Peer\PeerConnector;

/**
 * Eigenes Signatur-Geheimnis je Peer-Verbindung (Bug #540).
 *
 * Vorher lieh sich die Peer-Delegation das Event-Secret der Brain-Anbindung.
 * Seit AI Brain das pro Produkt vergibt, haben zwei Peers dort nichts
 * Gemeinsames mehr — jede Signatur schlug fehl, die Person wurde verworfen, und
 * beim Empfänger endete jeder Schreibzugriff in 403.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-brain-bridge.source', 'peppermint-verwaltung');
    config()->set('ai-brain-bridge.events.secret', 'mein-eigenes-produkt-secret');
    config()->set('ai-brain-bridge.peer.enabled', true);

    AiBrain::resolveActingUserUsing(fn () => 'bastian@example.test');
    AiBrain::resolveInboundUserUsing(fn (string $email) => geheimnisTestUser($email));
});

/**
 * Minimaler Nutzer — das Paket kennt das Modell des Produkts nicht, es geht nur
 * durch den Resolver.
 */
function geheimnisTestUser(string $email): Authenticatable
{
    return new class($email) implements Authenticatable
    {
        public function __construct(public string $email) {}

        public function getAuthIdentifierName(): string
        {
            return 'email';
        }

        public function getAuthIdentifier(): string
        {
            return $this->email;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };
}

function outboundConnector(?string $actingSecret = null): PeerConnector
{
    return PeerConnector::create([
        'direction' => 'outbound',
        'peer_slug' => 'peppermint-manager',
        'api_url' => 'https://manager.test',
        'api_token' => 'peer-token',
        'acting_secret' => $actingSecret,
        'status' => 'active',
    ]);
}

// --- Handshake ---------------------------------------------------------------

it('legt beim Verbinden auf beiden Seiten dasselbe Geheimnis ab', function () {
    $peers = app(PeerConnectionManager::class);

    $code = $peers->issueClaim();
    $bundle = PeerClaimCode::query()->first()->bundle;

    expect($bundle['acting_secret'])->toBeString()->not->toBeEmpty();

    // Endpoint-Seite: der inbound-Connector merkt sich dasselbe Geheimnis.
    $peers->claim($code, 'peppermint-manager');

    $inbound = PeerConnector::query()->where('direction', 'inbound')->first();

    expect($inbound->acting_secret)->toBe($bundle['acting_secret']);
});

// --- ausgehend ---------------------------------------------------------------

it('signiert mit dem Geheimnis der Verbindung, nicht mit dem Produkt-Secret', function () {
    outboundConnector('geheimnis-dieser-verbindung');
    Http::fake(['*' => Http::response(['ok' => true])]);

    app(PeerClient::class)->call('peppermint-manager', 'POST', '/api/tasks');

    Http::assertSent(function ($request) {
        $verbindung = 'sha256='.hash_hmac('sha256', 'bastian@example.test', 'geheimnis-dieser-verbindung');
        $produkt = 'sha256='.hash_hmac('sha256', 'bastian@example.test', 'mein-eigenes-produkt-secret');
        $gesendet = $request->header(ResolveAiBrainActingUser::ACTING_SIG_HEADER)[0] ?? null;

        return $gesendet === $verbindung && $gesendet !== $produkt;
    });
});

it('versorgt eine Altverbindung beim ersten Aufruf selbst', function () {
    $connector = outboundConnector();

    Http::fake([
        'manager.test/api/v1/peer/acting-secret' => Http::response(['status' => 'ok', 'peer_acting_secret' => 'stored']),
        '*' => Http::response(['ok' => true]),
    ]);

    app(PeerClient::class)->call('peppermint-manager', 'POST', '/api/tasks');

    $secret = $connector->fresh()->acting_secret;

    expect($secret)->toBeString()->not->toBeEmpty();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/peer/acting-secret')
        && $request['acting_secret'] === $secret);

    Http::assertSent(function ($request) use ($secret) {
        return str_ends_with($request->url(), '/api/tasks')
            && $request->header(ResolveAiBrainActingUser::ACTING_SIG_HEADER)[0]
                === 'sha256='.hash_hmac('sha256', 'bastian@example.test', $secret);
    });
});

it('legt nichts ab, wenn ein Catch-All nur freundlich 200 sagt', function () {
    $connector = outboundConnector();

    // Ohne Quittung ist ein 200 wertlos: der Peer koennte das Geheimnis nie
    // gesehen haben. Dann lieber weiter wie bisher signieren.
    Http::fake(['*' => Http::response(['ok' => true])]);

    app(PeerClient::class)->call('peppermint-manager', 'POST', '/api/tasks');

    expect($connector->fresh()->acting_secret)->toBeNull();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/tasks')
        && $request->header(ResolveAiBrainActingUser::ACTING_SIG_HEADER)[0]
            === 'sha256='.hash_hmac('sha256', 'bastian@example.test', 'mein-eigenes-produkt-secret'));
});

it('bleibt beim Altverhalten, wenn der Peer die Übergabe nicht kennt', function () {
    $connector = outboundConnector();

    Http::fake([
        'manager.test/api/v1/peer/acting-secret' => Http::response(['message' => 'Not Found'], 404),
        '*' => Http::response(['ok' => true]),
    ]);

    app(PeerClient::class)->call('peppermint-manager', 'POST', '/api/tasks');

    // Nichts gespeichert, und signiert wird wie bisher — ein Rollout in beliebiger
    // Reihenfolge darf nichts verschlechtern.
    expect($connector->fresh()->acting_secret)->toBeNull();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/tasks')
        && $request->header(ResolveAiBrainActingUser::ACTING_SIG_HEADER)[0]
            === 'sha256='.hash_hmac('sha256', 'bastian@example.test', 'mein-eigenes-produkt-secret'));
});

// --- eingehend ---------------------------------------------------------------

function inboundConnector(string $token, ?string $actingSecret): PeerConnector
{
    return PeerConnector::create([
        'direction' => 'inbound',
        'peer_slug' => 'peppermint-manager',
        'token_hash' => hash('sha256', $token),
        'acting_secret' => $actingSecret,
        'status' => 'active',
    ]);
}

function actingHeaders(string $email, string $secret, string $token): array
{
    return [
        ResolveAiBrainActingUser::ACTING_USER_HEADER => $email,
        ResolveAiBrainActingUser::ACTING_SIG_HEADER => 'sha256='.hash_hmac('sha256', $email, $secret),
        'Authorization' => 'Bearer '.$token,
    ];
}

it('prüft eingehend gegen das Geheimnis der Verbindung', function () {
    Route::middleware(ResolvePeerActingUser::class)->get('/_test/wer', fn () => response(
        Auth::user()?->email ?? 'niemand'
    ));

    inboundConnector('ihr-token', 'geheimnis-dieser-verbindung');

    $this->get('/_test/wer', actingHeaders('bastian@example.test', 'geheimnis-dieser-verbindung', 'ihr-token'))
        ->assertOk()
        ->assertSee('bastian@example.test');
});

it('verwirft eine Signatur mit dem falschen Geheimnis', function () {
    Route::middleware(ResolvePeerActingUser::class)->get('/_test/wer', fn () => response(
        Auth::user()?->email ?? 'niemand'
    ));

    inboundConnector('ihr-token', 'geheimnis-dieser-verbindung');

    // Genau der Fall aus #540: die Gegenstelle signiert mit ihrem Produkt-Secret.
    $this->get('/_test/wer', actingHeaders('bastian@example.test', 'fremdes-produkt-secret', 'ihr-token'))
        ->assertOk()
        ->assertSee('niemand');
});

it('fällt ohne Verbindungsgeheimnis auf das bisherige Verhalten zurück', function () {
    Route::middleware(ResolvePeerActingUser::class)->get('/_test/wer', fn () => response(
        Auth::user()?->email ?? 'niemand'
    ));

    inboundConnector('ihr-token', null);

    $this->get('/_test/wer', actingHeaders('bastian@example.test', 'mein-eigenes-produkt-secret', 'ihr-token'))
        ->assertOk()
        ->assertSee('bastian@example.test');
});

// --- Übergabe-Endpunkt -------------------------------------------------------

it('nimmt das Geheimnis nur mit gültigem Peer-Token entgegen', function () {
    inboundConnector('ihr-token', null);

    $this->postJson('/api/v1/peer/acting-secret', ['acting_secret' => str_repeat('a', 64)], [
        'Authorization' => 'Bearer falscher-token',
    ])->assertForbidden();

    $this->postJson('/api/v1/peer/acting-secret', ['acting_secret' => str_repeat('a', 64)], [
        'Authorization' => 'Bearer ihr-token',
    ])->assertOk();

    expect(PeerConnector::query()->where('direction', 'inbound')->first()->acting_secret)
        ->toBe(str_repeat('a', 64));
});

it('lässt ein hinterlegtes Geheimnis nicht überschreiben', function () {
    inboundConnector('ihr-token', 'bereits-vereinbart');

    // Sonst waere das Geheimnis wertlos: Wer den Token erbeutet, setzte sich ein
    // eigenes und behauptete danach jede beliebige Person.
    $this->postJson('/api/v1/peer/acting-secret', ['acting_secret' => str_repeat('b', 64)], [
        'Authorization' => 'Bearer ihr-token',
    ])->assertStatus(422);

    expect(PeerConnector::query()->where('direction', 'inbound')->first()->acting_secret)
        ->toBe('bereits-vereinbart');
});

it('signiert weiter wie bisher, wenn der Peer die Übergabe ablehnt', function () {
    $connector = outboundConnector();

    Http::fake([
        'manager.test/api/v1/peer/acting-secret' => Http::response(['message' => 'Kein Geheimnis hinterlegt.'], 422),
        '*' => Http::response(['ok' => true]),
    ]);

    app(PeerClient::class)->call('peppermint-manager', 'POST', '/api/tasks');

    // Nichts ablegen, was die Gegenseite nicht kennt — sonst schlüge ab jetzt
    // jede Signatur fehl.
    expect($connector->fresh()->acting_secret)->toBeNull();
});
