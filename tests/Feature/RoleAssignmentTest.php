<?php

use Peppermint\AiBrainBridge\Auth\RoleAssignment;

/**
 * Rollen aus der Ferne setzen — und die Riegel dabei (AI Brain #5314).
 *
 * Der Zweck dieser Tests sind nicht die Erfolgsfälle. Ein Werkzeug, das eine
 * Rolle vergeben kann, ist die empfindlichste Stelle eines Systems: Wer es
 * missbrauchen kann, braucht danach keine Lücke mehr. Geprüft wird deshalb vor
 * allem, was es NICHT tut.
 */
afterEach(function () {
    RoleAssignment::handleUsing(null);
    RoleAssignment::schuetze(['admin', 'administrator']);
});

it('weist einen unbekannten Modus ab, statt zu raten', function () {
    $ergebnis = RoleAssignment::setzen('wer@example.com', 'admin', 'loeschen');

    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['message'])->toContain('Unbekannter Modus');
});

it('weist leere Angaben ab', function () {
    expect(RoleAssignment::setzen('', 'admin', 'zuweisen')['ok'])->toBeFalse()
        ->and(RoleAssignment::setzen('wer@example.com', '', 'zuweisen')['ok'])->toBeFalse();
});

it('sagt deutlich, wenn das Produkt seine Rollen woanders führt', function () {
    // Ohne Spatie und ohne eigenen Handler ist die ehrliche Antwort „ich kann
    // das nicht" — nicht ein stiller Erfolg, der nichts bewirkt hat.
    $ergebnis = RoleAssignment::setzen('wer@example.com', 'admin', 'zuweisen');

    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['message'])->toContain('eigenen Handler');
});

it('überlässt einem Produkt mit eigenem Handler die Entscheidung', function () {
    RoleAssignment::handleUsing(fn (string $email, string $rolle, string $modus) => [
        'ok' => true,
        'message' => "eigener Weg: {$modus} {$rolle} für {$email}",
        'rollen' => ['sachbearbeiter'],
    ]);

    $ergebnis = RoleAssignment::setzen('luka@example.com', 'sachbearbeiter', 'zuweisen');

    expect($ergebnis['ok'])->toBeTrue()
        ->and($ergebnis['message'])->toBe('eigener Weg: zuweisen sachbearbeiter für luka@example.com')
        ->and($ergebnis['rollen'])->toBe(['sachbearbeiter']);
});

it('merkt sich, welche Rollen geschützt sind — und normalisiert die Schreibweise', function () {
    RoleAssignment::schuetze(['Administrator', 'OWNER']);

    // Kleingeschrieben verglichen, damit „Administrator" und „administrator"
    // nicht zwei verschiedene Dinge sind. Ein Schutz, der an der Grossschreibung
    // scheitert, schuetzt nichts.
    expect(RoleAssignment::geschuetzte())->toBe(['administrator', 'owner']);
});
