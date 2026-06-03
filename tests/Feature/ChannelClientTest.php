<?php

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Facades\AiBrain;

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
            $method = $body['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response(
                    ['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => ['protocolVersion' => '2025-06-18']],
                    200,
                    ['Mcp-Session-Id' => 'sess-1'],
                );
            }

            if ($method === 'tools/call') {
                $name = $body['params']['name'] ?? '';
                $payload = match ($name) {
                    'channel-list-tool' => ['ok' => true, 'channels' => ['price-research', 'offer-extraction']],
                    'channel-message-tool' => ['ok' => true, 'chat_id' => 42, 'channel' => 'price-research', 'status' => 'needs_input'],
                    'channel-thread-tool' => ['ok' => true, 'chat_id' => 42, 'status' => 'needs_input', 'messages' => [['role' => 'agent', 'content' => 'Welche Farbe?']]],
                    'channel-reply-tool' => ['ok' => true, 'chat_id' => 42, 'message_id' => 7, 'status' => 'running'],
                    default => ['ok' => false, 'error' => "unknown tool {$name}"],
                };

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $body['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => json_encode($payload)]]],
                ]);
            }
        }

        return Http::response([], 404);
    });
});

it('sends a channel message via the channel-message MCP tool', function () {
    $result = AiBrain::channel('price-research')->message(['product' => 'Poloshirt'], ref: 'vw-7');

    expect($result)->toMatchArray(['ok' => true, 'chat_id' => 42, 'channel' => 'price-research']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/mcp/brain')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return ($body['method'] ?? '') === 'tools/call'
            && ($body['params']['name'] ?? '') === 'channel-message-tool'
            && ($body['params']['arguments']['channel'] ?? '') === 'price-research'
            && ($body['params']['arguments']['payload']['product'] ?? '') === 'Poloshirt'
            && ($body['params']['arguments']['external_ref'] ?? '') === 'vw-7';
    });
});

it('polls a thread and replies via the matching MCP tools', function () {
    $thread = AiBrain::channel('price-research')->thread(42);
    expect($thread)->toMatchArray(['ok' => true, 'status' => 'needs_input'])
        ->and($thread['messages'][0]['content'])->toBe('Welche Farbe?');

    $reply = AiBrain::channel('price-research')->reply(42, 'Der blaue');
    expect($reply)->toMatchArray(['ok' => true, 'chat_id' => 42, 'status' => 'running']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/mcp/brain')
        && (json_decode($request->body(), true)['params']['name'] ?? '') === 'channel-reply-tool'
        && (json_decode($request->body(), true)['params']['arguments']['content'] ?? '') === 'Der blaue');
});

it('lists available channels via discovery', function () {
    expect(AiBrain::channels())->toBe(['price-research', 'offer-extraction']);
});

it('keeps the deprecated invoke() alias working', function () {
    $result = AiBrain::channel('price-research')->invoke(['product' => 'Cap']);

    expect($result)->toMatchArray(['ok' => true, 'chat_id' => 42]);
});
