<?php

use Peppermint\AiBrainBridge\Auth\RoleCatalog;

/**
 * Rollen melden statt pflegen (AI Brain #5245).
 */
afterEach(function () {
    RoleCatalog::resolveUsing(null);
});

it('meldet nichts, wenn es weder Resolver noch Spatie-Rollen gibt', function () {
    // Fail-quiet ist hier richtig: „keine Rollen" ist eine gültige Antwort,
    // ein Absturz wäre es nicht. Ein Produkt ohne Rollenmodell soll das
    // Werkzeug trotzdem registrieren dürfen.
    expect(RoleCatalog::alle())->toBe([]);
});

it('nimmt die Rollen des Produkts, wenn es sie selbst meldet', function () {
    RoleCatalog::resolveUsing(fn () => [
        ['name' => 'buchhaltung', 'label' => 'Buchhaltung', 'permissions' => ['view timesheets'], 'users' => 1],
    ]);

    expect(RoleCatalog::alle())->toBe([
        ['name' => 'buchhaltung', 'label' => 'Buchhaltung', 'permissions' => ['view timesheets'], 'users' => 1],
    ]);
});

it('füllt fehlende Angaben, statt sie zu erfinden', function () {
    RoleCatalog::resolveUsing(fn () => [['name' => 'employee']]);

    // Ohne Klartext steht in der Kontrollebene sonst ein leeres Feld; der
    // technische Name ist die schlechteste, aber ehrlichste Vorgabe.
    // `users: null` heißt „nicht gezählt" und ist NICHT dasselbe wie 0.
    expect(RoleCatalog::alle())->toBe([
        ['name' => 'employee', 'label' => 'employee', 'permissions' => [], 'users' => null],
    ]);
});
