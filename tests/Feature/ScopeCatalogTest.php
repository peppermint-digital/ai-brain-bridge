<?php

use Peppermint\AiBrainBridge\Auth\ScopeAssignment;
use Peppermint\AiBrainBridge\Auth\ScopeCatalog;

/**
 * Zuständigkeiten melden und setzen (AI Brain #9007).
 */
afterEach(function () {
    ScopeCatalog::resolveUsing(null);
    ScopeCatalog::resolvePersonUsing(null);
    ScopeAssignment::handleUsing(null);
});

it('meldet ohne Resolver keine Ebenen — und sagt, dass es keine gibt', function () {
    // Es gibt hier ABSICHTLICH keine Vorgabe wie bei spatie/laravel-permission:
    // Zuständigkeiten sind nichts, was ein geteiltes Paket erraten könnte.
    // `vorhanden() === false` ist das Signal, mit dem ein Produkt die
    // Fähigkeit gar nicht erst meldet.
    expect(ScopeCatalog::vorhanden())->toBeFalse();
    expect(ScopeCatalog::ebenen())->toBe([]);
});

it('normalisiert eine Ebene, statt Angaben zu erfinden', function () {
    ScopeCatalog::resolveUsing(fn () => [
        ['schluessel' => 'mandant', 'werte' => [['id' => 7]]],
    ]);

    // Ohne Klartext steht in AI Brain sonst ein technischer Name. Und
    // `alle_moeglich` ist VORGABE FALSE: Ein Produkt, das den Zustand „alle"
    // nicht kennt, darf ihn in AI Brain nicht angeboten bekommen.
    expect(ScopeCatalog::ebenen())->toBe([[
        'schluessel' => 'mandant',
        'beschriftung' => 'mandant',
        'mehrfach' => true,
        'alle_moeglich' => false,
        'alle_beschriftung' => null,
        'eltern' => null,
        'hinweis' => null,
        'werte' => [['id' => '7', 'label' => '7', 'eltern_id' => null]],
    ]]);
});

it('gibt die Eltern-Beziehung der zweiten Ebene weiter', function () {
    ScopeCatalog::resolveUsing(fn () => [
        ['schluessel' => 'mandant', 'beschriftung' => 'Mandant', 'alle_moeglich' => true, 'alle_beschriftung' => 'Alle Mandanten', 'werte' => [['id' => '3', 'label' => 'Schülerferienpass']]],
        ['schluessel' => 'bereich', 'beschriftung' => 'Bereich', 'eltern' => 'mandant', 'werte' => [['id' => '12', 'label' => 'NRW', 'eltern_id' => '3']]],
    ]);

    $ebenen = ScopeCatalog::ebenen();

    // Erst damit weiss AI Brain, dass ein Bereich nur unter einem
    // zugewiesenen Mandanten zu haben ist — sonst stünden sechzehn
    // Bundesländer ohne Zusammenhang in der Liste.
    expect($ebenen[1]['eltern'])->toBe('mandant');
    expect($ebenen[1]['werte'][0]['eltern_id'])->toBe('3');
    expect($ebenen[0]['alle_beschriftung'])->toBe('Alle Mandanten');
});

it('liefert die Zuweisung einer Person getrennt vom Katalog', function () {
    ScopeCatalog::resolvePersonUsing(fn (string $email) => [
        'zugewiesen' => ['mandant' => [3], 'bereich' => []],
        'alle' => ['mandant' => false],
    ]);

    // IDs kommen als Zeichenketten zurück, auch wenn das Produkt Zahlen
    // liefert: Über den MCP-Weg werden sie ohnehin welche, und ein Vergleich
    // 3 == "3" ist genau die Stelle, an der später ein Haken fehlt.
    expect(ScopeCatalog::person('a@b.de'))->toBe([
        'zugewiesen' => ['mandant' => ['3'], 'bereich' => []],
        'alle' => ['mandant' => false],
    ]);
});

it('gibt ohne Person-Resolver eine leere Zuweisung statt eines Fehlers', function () {
    expect(ScopeCatalog::person('a@b.de'))->toBe(['zugewiesen' => [], 'alle' => []]);
});

it('weist eine unbekannte Ebene ab, statt sie anzulegen', function () {
    ScopeCatalog::resolveUsing(fn () => [['schluessel' => 'mandant', 'werte' => []]]);
    ScopeAssignment::handleUsing(fn () => ['ok' => true, 'message' => 'gesetzt']);

    $ergebnis = ScopeAssignment::setzen('a@b.de', 'abteilung', ['1'], null);

    expect($ergebnis['ok'])->toBeFalse();
    expect($ergebnis['message'])->toContain('abteilung');
    expect($ergebnis['message'])->toContain('mandant');
});

it('setzt ohne Handler nichts und sagt es', function () {
    $ergebnis = ScopeAssignment::setzen('a@b.de', 'mandant', ['1'], null);

    expect($ergebnis['ok'])->toBeFalse();
    expect(ScopeAssignment::moeglich())->toBeFalse();
});

it('reicht die vollständige Liste an den Handler weiter — eine leere entzieht', function () {
    $gesehen = null;

    ScopeAssignment::handleUsing(function (string $email, string $ebene, array $ids, ?bool $alle) use (&$gesehen) {
        $gesehen = compact('email', 'ebene', 'ids', 'alle');

        return ['ok' => true, 'message' => 'gesetzt', 'zugewiesen' => ['bereich' => []], 'alle' => []];
    });

    // Eine leere Liste heisst hier „nichts mehr zugewiesen" und nicht „nichts
    // ändern". Das ist der Grund, warum es kein zuweisen/entziehen gibt: Auf
    // einer Menge wüsste am Ende niemand mehr, was eine leere Liste bedeutet.
    $ergebnis = ScopeAssignment::setzen(' a@b.de ', 'bereich', [], false);

    expect($gesehen)->toBe(['email' => 'a@b.de', 'ebene' => 'bereich', 'ids' => [], 'alle' => false]);
    expect($ergebnis['ok'])->toBeTrue();
});
