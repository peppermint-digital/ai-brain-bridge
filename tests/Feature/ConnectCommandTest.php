<?php

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Config\BridgeConfig;

beforeEach(function () {
    $this->store = sys_get_temp_dir().'/ai-brain-bridge-test-'.uniqid().'.json';
    config()->set('ai-brain-bridge.store_path', $this->store);
});

afterEach(function () {
    @unlink($this->store);
});

it('connects via claim code, stores the bundle and applies it to config', function () {
    Http::fake([
        '*/api/v1/connect/claim' => Http::response([
            'product_slug' => 'veranstaltungstool',
            'client_id' => 'cid-123',
            'client_secret' => 'sec-xyz',
            'scope' => 'mcp:use',
            'event_secret' => 'evt-secret',
            'brain_url' => 'https://brain.test',
            'brain_mcp_url' => 'https://brain.test/mcp/brain',
            'events_url' => 'https://brain.test/api/v1/events',
            'jwt_public_key_url' => 'https://brain.test/.well-known/ai-brain-public-key.pem',
        ]),
    ]);

    $this->artisan('ai-brain:connect', ['code' => 'CLAIMCODE', '--url' => 'https://brain.test'])
        ->assertExitCode(0);

    expect(is_file($this->store))->toBeTrue()
        ->and(config('ai-brain-bridge.oauth.client_id'))->toBe('cid-123')
        ->and(config('ai-brain-bridge.oauth.client_secret'))->toBe('sec-xyz')
        ->and(config('ai-brain-bridge.events.secret'))->toBe('evt-secret')
        ->and(config('ai-brain-bridge.mcp.brain_url'))->toBe('https://brain.test/mcp/brain')
        ->and(config('ai-brain-bridge.source'))->toBe('veranstaltungstool');
});

it('fails when the claim is rejected and stores nothing', function () {
    Http::fake(['*/api/v1/connect/claim' => Http::response(['message' => 'Ungültig'], 422)]);

    $this->artisan('ai-brain:connect', ['code' => 'BAD', '--url' => 'https://brain.test'])
        ->assertExitCode(1);

    expect(is_file($this->store))->toBeFalse();
});

it('reapplies a stored bundle over env defaults', function () {
    BridgeConfig::save([
        'oauth' => ['client_id' => 'stored-cid'],
        'events' => ['secret' => 'stored-evt'],
    ]);

    config()->set('ai-brain-bridge.oauth.client_id', 'env-cid');
    BridgeConfig::apply();

    expect(config('ai-brain-bridge.oauth.client_id'))->toBe('stored-cid')
        ->and(config('ai-brain-bridge.events.secret'))->toBe('stored-evt');
});
