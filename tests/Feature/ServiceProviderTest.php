<?php

use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Events\EventPublisher;

it('boots the package and binds the services', function () {
    expect(app(AiBrainManager::class))->toBeInstanceOf(AiBrainManager::class)
        ->and(app(OAuthTokenProvider::class))->toBeInstanceOf(OAuthTokenProvider::class)
        ->and(app(EventPublisher::class))->toBeInstanceOf(EventPublisher::class);
});

it('registers the signed inbound webhook route', function () {
    expect(Route::has('ai-brain-bridge.inbound'))->toBeTrue();
});

it('merges config with sensible defaults', function () {
    expect(config('ai-brain-bridge.oauth.scope'))->toBe('mcp:use')
        ->and(config('ai-brain-bridge.inbound.route'))->toBe('/webhooks/ai-brain');
});
