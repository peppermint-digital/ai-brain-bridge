<?php

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Mcp\McpClient;

beforeEach(function () {
    config()->set('ai-brain-bridge.base_url', 'https://brain.test');
    config()->set('ai-brain-bridge.oauth.client_id', 'cid');
    config()->set('ai-brain-bridge.oauth.client_secret', 'csec');

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/oauth/token')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        if (str_contains($url, '/mcp/brain')) {
            $body = json_decode($request->body(), true);

            if (($body['method'] ?? '') === 'initialize') {
                return Http::response(
                    ['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => ['protocolVersion' => '2025-06-18']],
                    200,
                    ['Mcp-Session-Id' => 'sess-1'],
                );
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $body['id'],
                'result' => ['structuredContent' => ['ok' => true]],
            ]);
        }

        return Http::response([], 404);
    });
});

it('hängt KEINEN Acting-User-Header an, wenn kein Resolver gesetzt ist', function () {
    AiBrain::call('list-projects-tool');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/mcp/brain')
        && ! $request->hasHeader(McpClient::ACTING_USER_HEADER));
});

it('hängt den Acting-User-Header an, wenn der Resolver eine E-Mail liefert', function () {
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    AiBrain::call('create-task-tool', ['title' => 'X']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/mcp/brain')
        && str_contains($request->body(), 'create-task-tool')
        && $request->hasHeader(McpClient::ACTING_USER_HEADER, 'martin@example.test'));
});

it('hängt KEINEN Header an, wenn der Resolver null liefert (Hintergrund-Job)', function () {
    AiBrain::resolveActingUserUsing(fn () => null);

    AiBrain::call('list-projects-tool');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/mcp/brain')
        && ! $request->hasHeader(McpClient::ACTING_USER_HEADER));
});

it('liest den Resolver aus der Config', function () {
    config()->set('ai-brain-bridge.acting_user.resolver', fn () => 'configured@example.test');

    // Manager neu auflösen, damit die Config greift.
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);

    AiBrain::call('list-projects-tool');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/mcp/brain')
        && $request->hasHeader(McpClient::ACTING_USER_HEADER, 'configured@example.test'));
});
