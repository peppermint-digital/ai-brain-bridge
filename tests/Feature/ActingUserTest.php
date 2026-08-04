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

it('signiert die Acting-User-Assertion mit dem Event-Secret', function () {
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    config()->set('ai-brain-bridge.source', 'mein-produkt');
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    AiBrain::call('create-task-tool', ['title' => 'X']);

    // Bug #520: Der HMAC deckt Produkt-Slug UND E-Mail ab. Vorher war die
    // Signatur ein universeller Ausweis, mit dem ein Produkt in fremden
    // Kontexten (u.a. an AI Brains Push-Gate) handeln konnte.
    $expected = 'sha256='.hash_hmac('sha256', 'mein-produkt|martin@example.test', 'shared-secret');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/mcp/brain')
        && str_contains($request->body(), 'create-task-tool')
        && $request->hasHeader(McpClient::ACTING_SIG_HEADER, $expected));
});

it('signiert NICHT ohne Event-Secret', function () {
    config()->set('ai-brain-bridge.events.secret', '');
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    AiBrain::call('list-projects-tool');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/mcp/brain')
        && $request->hasHeader(McpClient::ACTING_USER_HEADER, 'martin@example.test')
        && ! $request->hasHeader(McpClient::ACTING_SIG_HEADER));
});

it('actingUserHeaders() ist leer ohne Resolver (Direkt-HTTP läuft als Owner)', function () {
    expect(AiBrain::actingUserHeaders())->toBe([]);
});

it('actingUserHeaders() liefert den Acting-User-Header, wenn der Resolver greift', function () {
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    expect(AiBrain::actingUserHeaders())
        ->toBe([McpClient::ACTING_USER_HEADER => 'martin@example.test']);
});

it('actingUserHeaders() signiert mit dem Event-Secret', function () {
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    config()->set('ai-brain-bridge.source', 'mein-produkt');
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    expect(AiBrain::actingUserHeaders())->toBe([
        McpClient::ACTING_USER_HEADER => 'martin@example.test',
        McpClient::ACTING_SIG_HEADER => 'sha256='.hash_hmac('sha256', 'mein-produkt|martin@example.test', 'shared-secret'),
    ]);
});

it('peerActingUserHeaders() bleibt beim Altformat (Peer-Zuschreibung)', function () {
    // Produkt→Produkt prueft weiter E-Mail-only. Wuerde hier kontextgebunden
    // signiert, verwuerfe jeder Peer mit aelterem Paket den Header und die
    // Datensaetze verloeren ihren Urheber.
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    config()->set('ai-brain-bridge.source', 'mein-produkt');
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    expect(AiBrain::peerActingUserHeaders())->toBe([
        McpClient::ACTING_USER_HEADER => 'martin@example.test',
        McpClient::ACTING_SIG_HEADER => 'sha256='.hash_hmac('sha256', 'martin@example.test', 'shared-secret'),
    ]);
});

it('actingUserHeaders() ist leer, wenn der Resolver null liefert (Hintergrund-Job)', function () {
    AiBrain::resolveActingUserUsing(fn () => null);

    expect(AiBrain::actingUserHeaders())->toBe([]);
});

it('liest den Resolver aus der Config', function () {
    config()->set('ai-brain-bridge.acting_user.resolver', fn () => 'configured@example.test');

    // Manager neu auflösen, damit die Config greift.
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);

    AiBrain::call('list-projects-tool');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/mcp/brain')
        && $request->hasHeader(McpClient::ACTING_USER_HEADER, 'configured@example.test'));
});

it('faellt ohne konfigurierten Slug auf das Altformat zurueck statt auf leeren Kontext', function () {
    // Sonst ginge `|{email}` raus: weder gueltig-neu noch gueltig-alt. AI Brain
    // antwortete 403 und die Anbindung waere still kaputt — genau die Sorte
    // Fehler, die erst beim Kunden auffaellt.
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    config()->set('ai-brain-bridge.source', '');
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    $headers = AiBrain::actingUserHeaders();

    expect($headers[McpClient::ACTING_SIG_HEADER])
        ->toBe('sha256='.hash_hmac('sha256', 'martin@example.test', 'shared-secret'))
        ->not->toContain(hash_hmac('sha256', '|martin@example.test', 'shared-secret'));
});
