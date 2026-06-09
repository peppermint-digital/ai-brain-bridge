<?php

use Illuminate\Support\Facades\Http;

/**
 * Fakt den kompletten Komm-Layer (OAuth + /mcp/brain + /api/v1/events) und
 * echo't im Channel-Loop den gesendeten Sentinel zurück → Selftest grün.
 *
 * @param  bool  $mcpOk  list-projects-tool grün/rot simulieren
 */
function fakeCommLayer(bool $mcpOk = true): void
{
    config()->set('ai-brain-bridge.base_url', 'https://brain.test');
    config()->set('ai-brain-bridge.oauth.client_id', 'cid');
    config()->set('ai-brain-bridge.oauth.client_secret', 'csec');
    config()->set('ai-brain-bridge.events.secret', 'evsec');
    config()->set('ai-brain-bridge.source', 'test-product');

    $sentinel = null;

    Http::fake(function ($request) use (&$sentinel, $mcpOk) {
        $url = $request->url();

        if (str_contains($url, '/oauth/token')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        if (str_contains($url, '/api/v1/events')) {
            return Http::response(['ok' => true], 202);
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
                $args = $body['params']['arguments'] ?? [];

                if ($name === 'channel-message-tool') {
                    $sentinel = $args['payload']['ping'] ?? null;
                }

                $payload = match ($name) {
                    'list-projects-tool' => $mcpOk ? ['ok' => true, 'projects' => []] : ['ok' => false, 'error' => 'down'],
                    'channel-message-tool' => ['ok' => true, 'chat_id' => 1, 'status' => 'queued'],
                    'channel-thread-tool' => ['ok' => true, 'chat_id' => 1, 'status' => 'done', 'result' => $sentinel],
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
}

it('exits 0 when all rails are green', function () {
    fakeCommLayer();

    $this->artisan('ai-brain:selftest', ['--timeout' => 10])->assertExitCode(0);
});

it('fails when the bridge is not configured', function () {
    config()->set('ai-brain-bridge.oauth.client_id', null);

    $this->artisan('ai-brain:selftest')->assertExitCode(1);
});

it('exits 1 when the MCP probe is red', function () {
    fakeCommLayer(mcpOk: false);

    $this->artisan('ai-brain:selftest', ['--rail' => 'mcp'])->assertExitCode(1);
});

it('rejects an unknown rail', function () {
    fakeCommLayer();

    $this->artisan('ai-brain:selftest', ['--rail' => 'nope'])->assertExitCode(2);
});

it('verifies the channel round-trip via the sentinel echo', function () {
    fakeCommLayer();

    $this->artisan('ai-brain:selftest', ['--rail' => 'channel', '--timeout' => 10])->assertExitCode(0);
});
