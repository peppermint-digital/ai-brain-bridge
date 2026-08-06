<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser;
use Peppermint\AiBrainBridge\Http\Middleware\ResolvePeerActingUser;
use Peppermint\AiBrainBridge\Peer\PeerClient;
use Peppermint\AiBrainBridge\Peer\PeerConnector;

/**
 * Handelnde Person über eine Produkt-Verbindung (#3459).
 *
 * Eine Systemverbindung trägt bewusst keine Person. Ohne diese Delegation
 * entstehen beim aufgerufenen Produkt deshalb Datensätze ohne Urheber — das
 * aufrufende Produkt weiß aber, wer sie ausgelöst hat.
 */
uses(RefreshDatabase::class);

/**
 * Minimaler Nutzer: das Paket kennt das Modell des Produkts nicht, es geht nur
 * durch den Resolver.
 */
function peerTestUser(string $email): Authenticatable
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

beforeEach(function () {
    config()->set('ai-brain-bridge.source', 'peppermint-manager');
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');

    PeerConnector::create([
        'direction' => 'outbound',
        'peer_slug' => 'peppermint-crm',
        'api_url' => 'https://crm.test',
        'api_token' => 'peer-token',
        // Signiert wird seit #540 mit dem Geheimnis DIESER Verbindung; hier
        // dasselbe wie das Event-Secret, damit die Erwartungen unten die
        // Delegation prüfen und nicht die Schlüsselwahl (dafür gibt es
        // PeerActingSecretTest).
        'acting_secret' => 'shared-secret',
        'is_active' => true,
    ]);
});

// --- ausgehend ---------------------------------------------------------------

it('schickt die handelnde Person signiert an den Peer', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    AiBrain::resolveActingUserUsing(fn () => 'bastian@example.test');

    app(PeerClient::class)->call('peppermint-crm', 'GET', '/api/tasks');

    Http::assertSent(function ($request) {
        $expected = 'sha256='.hash_hmac('sha256', 'bastian@example.test', 'shared-secret');

        return $request->header(ResolveAiBrainActingUser::ACTING_USER_HEADER)[0] === 'bastian@example.test'
            && $request->header(ResolveAiBrainActingUser::ACTING_SIG_HEADER)[0] === $expected;
    });
});

it('bleibt unter asService identitätslos', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    AiBrain::resolveActingUserUsing(fn () => 'bastian@example.test');

    // Hintergrund-Aufrufe (Health-Checks, geplante Jobs) haben keinen Menschen
    // dahinter und dürfen auch keinen behaupten.
    AiBrain::asService(fn () => app(PeerClient::class)->call('peppermint-crm', 'GET', '/api/tasks'));

    Http::assertSent(fn ($request) => $request->header(ResolveAiBrainActingUser::ACTING_USER_HEADER) === []);
});

// --- eingehend ---------------------------------------------------------------

beforeEach(function () {
    Route::middleware(ResolvePeerActingUser::class)->get('/_test/peer-who', fn () => response(
        Auth::user()?->email ?? 'niemand'
    ));
});

function peerHeaders(string $email, string $secret): array
{
    return [
        ResolveAiBrainActingUser::ACTING_USER_HEADER => $email,
        ResolveAiBrainActingUser::ACTING_SIG_HEADER => 'sha256='.hash_hmac('sha256', $email, $secret),
    ];
}

it('übernimmt die behauptete Person, wenn der Aufruf keine hat', function () {
    AiBrain::resolveInboundUserUsing(fn (string $email) => peerTestUser($email));

    $this->get('/_test/peer-who', peerHeaders('bastian@example.test', 'shared-secret'))
        ->assertOk()
        ->assertSee('bastian@example.test');
});

it('verwirft eine gefälschte Signatur', function () {
    AiBrain::resolveInboundUserUsing(fn (string $email) => peerTestUser($email));

    $this->get('/_test/peer-who', [
        ResolveAiBrainActingUser::ACTING_USER_HEADER => 'fremd@example.test',
        ResolveAiBrainActingUser::ACTING_SIG_HEADER => 'sha256=falsch',
    ])->assertOk()->assertSee('niemand');
});

it('lässt einen personengebundenen Aufruf unangetastet', function () {
    AiBrain::resolveInboundUserUsing(fn (string $email) => peerTestUser($email));

    $eigentliche = peerTestUser('kollegin@example.test');

    // Genau hier liegt der Unterschied zum Brain-Pendant: ein persönlicher
    // Token darf sich nicht per Header in jemand anderen verwandeln, sonst
    // wäre die Trennung von Systemverbindung und Person wieder aufgehoben.
    // Direkt gegen die Middleware statt über eine Route — eine Closure lässt
    // sich nicht als Route-Middleware registrieren.
    $request = Request::create('/beliebig', 'GET', server: [
        'HTTP_'.str_replace('-', '_', strtoupper(ResolveAiBrainActingUser::ACTING_USER_HEADER)) => 'bastian@example.test',
        'HTTP_'.str_replace('-', '_', strtoupper(ResolveAiBrainActingUser::ACTING_SIG_HEADER)) => 'sha256='.hash_hmac('sha256', 'bastian@example.test', 'shared-secret'),
    ]);
    $request->setUserResolver(fn () => $eigentliche);

    (new ResolvePeerActingUser)->handle($request, fn ($r) => new Response('ok'));

    expect($request->user()->email)->toBe('kollegin@example.test');
});
