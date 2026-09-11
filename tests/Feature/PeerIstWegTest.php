<?php

/**
 * Die Peer-Mechanik ist aus dem Paket entfernt (#5400).
 *
 * ## Was hier vorher stand
 *
 * Vier Testdateien zu Verbindungsaufbau, Anspruchscodes, Peer-Token und
 * Peer-Zuschreibung. Die Mechanik gibt es nicht mehr: Kein Produkt hat sie
 * noch benutzt, der Verbindungsaufbau war abgeschaltet, und die letzten
 * Nutzer — Datenaufrufe und Statusproben — gehen über AI Brain.
 *
 * An ihre Stelle tritt die umgekehrte Zusage. Sie ist hier mehr wert als
 * gelöschte Tests, denn dieses Paket steckt in zwölf Anwendungen: Käme die
 * Mechanik versehentlich zurück, fiele es sonst nirgends auf.
 */
it('kennt keine Peer-Klassen mehr', function () {
    $verschwunden = [
        'Peer\\PeerClient',
        'Peer\\PeerConnectionManager',
        'Peer\\PeerConnector',
        'Peer\\PeerClaimCode',
        'Peer\\PeerTokenIssuer',
        'Http\\Middleware\\VerifyPeerToken',
        'Http\\Middleware\\ResolvePeerActingUser',
        'Http\\Controllers\\PeerConnectController',
    ];

    $noch_da = array_values(array_filter(
        $verschwunden,
        fn (string $k): bool => class_exists('Peppermint\\AiBrainBridge\\'.$k)
            || interface_exists('Peppermint\\AiBrainBridge\\'.$k),
    ));

    expect($noch_da)->toBe([]);
});

it('registriert keine Peer-Middleware und keine Peer-Routen mehr', function () {
    $aliase = app('router')->getMiddleware();

    expect(array_keys($aliase))->not->toContain('peer.auth')
        ->and(array_keys($aliase))->not->toContain('peer.acting-user');

    $namen = collect(app('router')->getRoutes())->map(fn ($r) => $r->getName())->filter();

    expect($namen->filter(fn ($n) => str_starts_with((string) $n, 'peer.'))->values()->all())->toBe([]);
});
