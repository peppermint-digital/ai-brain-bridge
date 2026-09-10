<?php

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Ein Vorgang bei einem anderen System — über Brain (AI Brain #5243).
 *
 * Der Ersatz für die Peer-Schiene. Geprüft wird, dass der Aufrufer einen
 * VORGANG nennt (kein Zielsystem) und dass Fehler unterscheidbar
 * zurückkommen, statt als Ausnahme hochzuschlagen.
 */
beforeEach(function () {
    config()->set('ai-brain-bridge.base_url', 'https://brain.test');
    config()->set('ai-brain-bridge.oauth.token_url', 'https://brain.test/oauth/token');
    config()->set('ai-brain-bridge.oauth.client_id', 'produkt');
    config()->set('ai-brain-bridge.oauth.client_secret', 'geheim');

    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
    ]);
});

it('nennt einen Vorgang und bekommt das Ergebnis des Zielsystems', function () {
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/gateway' => Http::response([
            'ok' => true,
            'data' => ['id' => 42],
            'product' => 'peppermint-manager',
        ]),
    ]);

    $ergebnis = AiBrain::gateway('tasks.create', ['title' => 'Ticket 5']);

    expect($ergebnis['ok'])->toBeTrue()
        ->and($ergebnis['data']['id'])->toBe(42)
        // Welches System es bedient hat, steht in der Antwort — der Aufrufer
        // musste es nicht wissen.
        ->and($ergebnis['product'])->toBe('peppermint-manager');

    Http::assertSent(fn ($anfrage) => str_contains($anfrage->url(), '/api/v1/gateway')
        && $anfrage['capability'] === 'tasks.create'
        && $anfrage['arguments'] === ['title' => 'Ticket 5']);
});

it('gibt die Fehlerklasse weiter, statt sie zu verschlucken', function () {
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/gateway' => Http::response([
            'ok' => false,
            'error' => 'not_allowed',
            'message' => 'Für „tasks.create" ist peppermint-verwaltung nicht freigeschaltet.',
        ], 403),
    ]);

    $ergebnis = AiBrain::gateway('tasks.create');

    // „darf nicht" und „antwortet nicht" verlangen verschiedene Reaktionen.
    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['error'])->toBe('not_allowed')
        ->and($ergebnis['message'])->toContain('nicht freigeschaltet');
});

it('behandelt einen Ausfall von Brain wie ein nicht erreichbares Zielsystem', function () {
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/gateway' => fn () => throw new \Illuminate\Http\Client\ConnectionException('keine Verbindung'),
    ]);

    $ergebnis = AiBrain::gateway('tasks.create');

    // Kein Hochschlagen: Der Aufrufer soll denselben Fall behandeln wie ein
    // stummes Zielsystem — sonst steht der Ticket-Dialog mit einem Stacktrace da.
    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['error'])->toBe('unreachable')
        ->and($ergebnis['message'])->toContain('nicht erreichbar');
});

it('nennt ein Zielsystem nur, wenn es ausdrücklich gemeint ist', function () {
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/gateway' => Http::response(['ok' => true, 'data' => []]),
    ]);

    AiBrain::gateway('tasks.list');
    Http::assertSent(fn ($a) => str_contains($a->url(), 'gateway') && ! isset($a['product']));

    AiBrain::gateway('tasks.list', [], 'peppermint-manager');
    Http::assertSent(fn ($a) => str_contains($a->url(), 'gateway') && ($a['product'] ?? null) === 'peppermint-manager');
});
