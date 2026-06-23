<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Connect\Connector;
use Peppermint\AiBrainBridge\Http\Controllers\ConnectController;

beforeEach(function () {
    $this->store = sys_get_temp_dir().'/ai-brain-bridge-test-'.uniqid().'.json';
    config()->set('ai-brain-bridge.store_path', $this->store);
});

afterEach(function () {
    @unlink($this->store);
});

function fakeClaimOk(): void
{
    Http::fake([
        '*/api/v1/connect/claim' => Http::response([
            'product_slug' => 'shop',
            'client_id' => 'cid-9',
            'client_secret' => 'sec-9',
            'scope' => 'mcp:use',
            'event_secret' => 'evt-9',
            'brain_url' => 'https://brain.test',
            'brain_mcp_url' => 'https://brain.test/mcp/brain',
            'events_url' => 'https://brain.test/api/v1/events',
            'jwt_public_key_url' => 'https://brain.test/.well-known/ai-brain-public-key.pem',
        ]),
    ]);
}

it('connects and applies config via the Connector service', function () {
    fakeClaimOk();

    $result = app(Connector::class)->connect('CLAIMCODE12345678', 'https://brain.test');

    expect($result['ok'])->toBeTrue()
        ->and($result['product_slug'])->toBe('shop')
        ->and(config('ai-brain-bridge.oauth.client_id'))->toBe('cid-9')
        ->and(config('ai-brain-bridge.events.secret'))->toBe('evt-9');
});

it('reports a connect failure without throwing', function () {
    Http::fake(['*/api/v1/connect/claim' => Http::response(['message' => 'abgelaufen'], 422)]);

    $result = app(Connector::class)->connect('BADCODE1234567890', 'https://brain.test');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('abgelaufen')
        ->and(is_file($this->store))->toBeFalse();
});

it('reflects connection status', function () {
    expect(app(Connector::class)->status()['connected'])->toBeFalse();

    fakeClaimOk();
    app(Connector::class)->connect('CLAIMCODE12345678', 'https://brain.test');

    expect(app(Connector::class)->status()['connected'])->toBeTrue();
});

it('connects via the HTTP controller (200)', function () {
    fakeClaimOk();

    $ok = app(ConnectController::class)->connect(
        Request::create('/ai-brain/connect', 'POST', ['code' => 'CLAIMCODE12345678', 'url' => 'https://brain.test']),
    );

    expect($ok->getStatusCode())->toBe(200);
});

it('fails closed via the HTTP controller (422)', function () {
    Http::fake(['*/api/v1/connect/claim' => Http::response(['message' => 'nope'], 422)]);

    $bad = app(ConnectController::class)->connect(
        Request::create('/ai-brain/connect', 'POST', ['code' => 'BADCODE1234567890', 'url' => 'https://brain.test']),
    );

    expect($bad->getStatusCode())->toBe(422);
});

it('registers the connect route only when enabled', function () {
    expect(app('router')->getRoutes()->hasNamedRoute('ai-brain-bridge.connect'))->toBeFalse();
});
