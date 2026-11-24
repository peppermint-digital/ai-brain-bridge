<?php

use Illuminate\Support\Facades\Route;

/**
 * Ohne Freischaltung existiert der Umbau für das Produkt nicht (AI Brain #5266).
 *
 * Das ist der Grund, warum niemand durch diese Änderung ausgesperrt werden kann:
 * Ein Produkt, das nichts einschaltet, bekommt nicht einmal die Routen.
 */
it('registriert ohne Freischaltung keine Anmelde-Routen', function () {
    expect(Route::has('ai-brain-bridge.login.redirect'))->toBeFalse()
        ->and(Route::has('ai-brain-bridge.login.callback'))->toBeFalse();

    $this->get('/auth/brain/redirect')->assertNotFound();
});

it('registriert ohne Freischaltung auch keinen Verbinden-Weg', function () {
    expect(Route::has('ai-brain-bridge.login.connect'))->toBeFalse();

    $this->get('/auth/brain/verbinden')->assertNotFound();
});
