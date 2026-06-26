<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\Peer\PeerConnectionManager;
use Peppermint\AiBrainBridge\Peer\PeerConnector;
use Peppermint\AiBrainBridge\Peer\PeerToken;
use Peppermint\AiBrainBridge\Peer\PeerTokenIssuer;
use Peppermint\AiBrainBridge\Peer\RevocablePeerTokenIssuer;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-brain-bridge.source', 'peppermint-crm');
    config()->set('ai-brain-bridge.peer.api_url', 'https://crm.test');
});

/*
|--------------------------------------------------------------------------
| #2582 — Token-Scoping
|--------------------------------------------------------------------------
*/

it('carries scopes through claim into the inbound connector', function () {
    $mgr = app(PeerConnectionManager::class);

    $bundle = $mgr->claim($mgr->issueClaim(['contacts.read', 'orders.write']));

    expect($bundle['scopes'])->toBe(['contacts.read', 'orders.write']);

    $inbound = PeerConnector::where('direction', 'inbound')->first();
    expect($inbound->scopes)->toBe(['contacts.read', 'orders.write']);
});

it('grants full access when no scopes are set (backward compatible)', function () {
    $c = new PeerConnector(['scopes' => null]);

    expect($c->allowsScope('anything'))->toBeTrue();
});

it('enforces the scope on the peer.auth middleware', function () {
    Route::middleware('peer.auth:contacts.read')->get('/peer-test/contacts', fn () => response()->json(['ok' => true]));

    $mgr = app(PeerConnectionManager::class);

    // Token MIT passendem Scope → 200.
    $allowed = $mgr->claim($mgr->issueClaim(['contacts.read']))['api_token'];
    $this->withToken($allowed)->getJson('/peer-test/contacts')->assertOk();

    // Token mit anderem Scope → 403.
    $denied = $mgr->claim($mgr->issueClaim(['orders.write']))['api_token'];
    $this->withToken($denied)->getJson('/peer-test/contacts')->assertForbidden();

    // Token OHNE Scopes → voller Zugriff → 200.
    $full = $mgr->claim($mgr->issueClaim())['api_token'];
    $this->withToken($full)->getJson('/peer-test/contacts')->assertOk();
});

/*
|--------------------------------------------------------------------------
| #2583 — Revoke (nativer Token) + Rotation
|--------------------------------------------------------------------------
*/

it('invalidates the native token on revoke via a RevocablePeerTokenIssuer', function () {
    $issuer = new RecordingRevocableIssuer;
    app()->instance(PeerTokenIssuer::class, $issuer);

    $mgr = app(PeerConnectionManager::class);
    $mgr->claim($mgr->issueClaim());

    $id = PeerConnector::where('direction', 'inbound')->first()->id;
    $mgr->revoke($id);

    expect($issuer->revoked)->toBe(['ref-1']);
});

it('rotates an inbound token: new works, old dies, scopes preserved, lineage linked', function () {
    $mgr = app(PeerConnectionManager::class);
    $oldToken = $mgr->claim($mgr->issueClaim(['contacts.read']))['api_token'];
    $old = PeerConnector::where('direction', 'inbound')->first();

    expect($mgr->verifyInboundToken($oldToken))->toBeTrue();

    $new = $mgr->rotateInbound($old->id);

    expect($new)->toBeInstanceOf(PeerToken::class)
        ->and($mgr->verifyInboundToken($oldToken))->toBeFalse()      // alter Token tot
        ->and($mgr->verifyInboundToken($new->plain))->toBeTrue();    // neuer Token lebt

    $newConnector = PeerConnector::where('direction', 'inbound')->active()->first();
    expect($newConnector->scopes)->toBe(['contacts.read'])           // Scopes übernommen
        ->and($old->fresh()->status)->toBe('revoked')
        ->and($old->fresh()->replaced_by_id)->toBe($newConnector->id); // Lineage
});

it('returns null when rotating a non-existent or non-inbound connector', function () {
    $outbound = PeerConnector::create([
        'direction' => 'outbound', 'peer_slug' => 'x', 'api_url' => 'https://x.test',
        'api_token' => 'tok', 'status' => 'active',
    ]);

    expect(app(PeerConnectionManager::class)->rotateInbound($outbound->id))->toBeNull()
        ->and(app(PeerConnectionManager::class)->rotateInbound(99999))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| #2584 — Capability-Discovery
|--------------------------------------------------------------------------
*/

it('discovers peer capabilities from the OpenAPI url and lists the paths', function () {
    PeerConnector::create([
        'direction' => 'outbound', 'peer_slug' => 'manager', 'api_url' => 'https://mgr.test',
        'api_token' => 'tok', 'openapi_url' => 'https://mgr.test/openapi.json', 'status' => 'active',
    ]);

    Http::fake(['https://mgr.test/openapi.json' => Http::response([
        'paths' => [
            '/api/v1/orders' => ['get' => [], 'post' => []],
            '/api/v1/orders/{id}' => ['delete' => [], 'parameters' => []],
        ],
    ])]);

    $caps = app(\Peppermint\AiBrainBridge\Peer\PeerClient::class)->capabilities('manager');

    expect($caps['ok'])->toBeTrue()
        ->and($caps['openapi_url'])->toBe('https://mgr.test/openapi.json')
        ->and($caps['paths'])->toBe(['DELETE /api/v1/orders/{id}', 'GET /api/v1/orders', 'POST /api/v1/orders']);
});

it('reports cleanly when a peer offers no OpenAPI url', function () {
    PeerConnector::create([
        'direction' => 'outbound', 'peer_slug' => 'manager', 'api_url' => 'https://mgr.test',
        'api_token' => 'tok', 'status' => 'active',
    ]);

    $caps = app(\Peppermint\AiBrainBridge\Peer\PeerClient::class)->capabilities('manager');

    expect($caps['ok'])->toBeFalse()->and($caps['paths'])->toBe([]);
});

/**
 * Test-Issuer: revocable, vergibt deterministische Tokens/Refs und merkt sich,
 * welche Refs widerrufen wurden.
 */
class RecordingRevocableIssuer implements RevocablePeerTokenIssuer
{
    /** @var list<string> */
    public array $revoked = [];

    private int $n = 0;

    public function issue(): string
    {
        return $this->issueWithRef()->plain;
    }

    public function issueWithRef(): PeerToken
    {
        $this->n++;

        return new PeerToken("native-token-{$this->n}", "ref-{$this->n}");
    }

    public function revokeByRef(string $ref): void
    {
        $this->revoked[] = $ref;
    }
}
