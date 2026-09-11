<?php

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Mcp\McpClient;

/**
 * Legt einen One-Click-Claim-Store mit dem gegebenen Slug an und liefert den Pfad.
 * Der Store — nicht die Config — bestimmt den Signatur-Kontext.
 */
function bridgeStoreWithSlug(string $slug): string
{
    $path = sys_get_temp_dir().'/bridge-store-'.uniqid().'.json';
    file_put_contents($path, json_encode(['source' => $slug]));

    return $path;
}

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
    config()->set('ai-brain-bridge.store_path', bridgeStoreWithSlug('mein-produkt'));
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);
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
    config()->set('ai-brain-bridge.store_path', bridgeStoreWithSlug('mein-produkt'));
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    expect(AiBrain::actingUserHeaders())->toBe([
        McpClient::ACTING_USER_HEADER => 'martin@example.test',
        McpClient::ACTING_SIG_HEADER => 'sha256='.hash_hmac('sha256', 'mein-produkt|martin@example.test', 'shared-secret'),
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

it('signiert das Altformat, wenn kein Claim-Store da ist (nicht angebunden)', function () {
    // Ohne One-Click-Store kennt AI Brain uns unter keinem Slug. Ein geratener
    // Kontext (die Config faellt auf APP_NAME zurueck!) erzeugte eine Signatur,
    // die AI Brain mit 403 abweist — die Anbindung waere still kaputt.
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    config()->set('ai-brain-bridge.source', 'Peppermint Manager');   // APP_NAME-Fallback
    config()->set('ai-brain-bridge.store_path', '/nicht/vorhanden.json');
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    expect(AiBrain::actingUserHeaders()[McpClient::ACTING_SIG_HEADER])
        ->toBe('sha256='.hash_hmac('sha256', 'martin@example.test', 'shared-secret'));
});

it('signiert mit dem Slug aus dem Claim-Store, nicht mit dem Config-Wert', function () {
    // Der Store ist die Wahrheit: dort steht der Slug, unter dem AI Brain uns
    // kennt. Die Config kann daneben etwas ganz anderes stehen haben.
    $store = sys_get_temp_dir().'/bridge-store-'.uniqid().'.json';
    file_put_contents($store, json_encode(['source' => 'peppermint-manager']));

    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    config()->set('ai-brain-bridge.source', 'Peppermint Manager');   // absichtlich falsch
    config()->set('ai-brain-bridge.store_path', $store);
    app()->forgetInstance(\Peppermint\AiBrainBridge\AiBrainManager::class);
    AiBrain::resolveActingUserUsing(fn () => 'martin@example.test');

    expect(AiBrain::actingUserHeaders()[McpClient::ACTING_SIG_HEADER])
        ->toBe('sha256='.hash_hmac('sha256', 'peppermint-manager|martin@example.test', 'shared-secret'));

    @unlink($store);
});
