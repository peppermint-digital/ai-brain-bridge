<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Peer\PeerClaimCode;
use Peppermint\AiBrainBridge\Peer\PeerClient;
use Peppermint\AiBrainBridge\Peer\PeerConnectionManager;
use Peppermint\AiBrainBridge\Peer\PeerConnector;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai-brain-bridge.source', 'peppermint-crm');
    config()->set('ai-brain-bridge.peer.api_url', 'https://crm.test');
});

it('issues a claim, lets a peer redeem it, and tracks the inbound token (no Brain)', function () {
    $mgr = app(PeerConnectionManager::class);

    $code = $mgr->issueClaim();
    expect(PeerClaimCode::count())->toBe(1);

    $bundle = $mgr->claim($code);
    expect($bundle['peer_slug'])->toBe('peppermint-crm')
        ->and($bundle['api_url'])->toBe('https://crm.test')
        ->and($bundle['api_token'])->not->toBeEmpty()
        ->and(PeerConnector::where('direction', 'inbound')->active()->count())->toBe(1)
        ->and($mgr->verifyInboundToken($bundle['api_token']))->toBeTrue();

    // Einmalig: zweite Einlösung schlägt fehl.
    expect($mgr->claim($code))->toBeNull();
});

it('redeems a peer code and stores an outbound connector (direct, no Brain)', function () {
    Http::fake([
        '*/api/v1/connect/claim' => Http::response([
            'peer_slug' => 'peppermint-verwaltung',
            'api_url' => 'https://vw.test',
            'api_token' => 'tok-xyz',
            'openapi_url' => null,
        ]),
    ]);

    $res = app(PeerConnectionManager::class)->redeem('https://vw.test', 'SOMECODE12345678');

    expect($res['ok'])->toBeTrue()
        ->and($res['peer_slug'])->toBe('peppermint-verwaltung');

    $c = PeerConnector::where('direction', 'outbound')->first();
    expect($c->peer_slug)->toBe('peppermint-verwaltung')
        ->and($c->api_token)->toBe('tok-xyz')
        ->and($c->api_url)->toBe('https://vw.test');
});

it('revokes a connector so its token stops working', function () {
    $mgr = app(PeerConnectionManager::class);
    $bundle = $mgr->claim($mgr->issueClaim());

    expect($mgr->verifyInboundToken($bundle['api_token']))->toBeTrue();

    $id = PeerConnector::where('direction', 'inbound')->first()->id;

    expect($mgr->revoke($id))->toBeTrue()
        ->and($mgr->verifyInboundToken($bundle['api_token']))->toBeFalse();
});

it('calls a connected peer directly with the stored bearer token', function () {
    PeerConnector::create([
        'direction' => 'outbound',
        'peer_slug' => 'crm',
        'api_url' => 'https://crm.test',
        'api_token' => 'tok',
        'status' => 'active',
    ]);

    Http::fake(['https://crm.test/*' => Http::response(['data' => [1, 2]], 200)]);

    $res = app(PeerClient::class)->call('crm', 'GET', '/api/v1/contacts');

    expect($res['ok'])->toBeTrue()->and($res['status'])->toBe(200);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));
});

it('fails the peer client cleanly when no active connector exists', function () {
    $res = app(PeerClient::class)->call('unknown', 'GET', '/x');

    expect($res['ok'])->toBeFalse()->and($res['status'])->toBe(0);
});
