<?php

/**
 * Ohne Freischaltung existiert der Umbau für das Produkt nicht (AI Brain #5266).
 *
 * Das ist der Grund, warum niemand durch diese Änderung ausgesperrt werden kann:
 * Ein Produkt, das nichts einschaltet, bekommt nicht einmal die Routen.
 */
it('registriert ohne Freischaltung keine Anmelde-Routen', function () {
    expect(\Illuminate\Support\Facades\Route::has('ai-brain-bridge.login.redirect'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('ai-brain-bridge.login.callback'))->toBeFalse();

    $this->get('/auth/brain/redirect')->assertNotFound();
});
