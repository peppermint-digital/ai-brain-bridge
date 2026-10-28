<?php

use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\Auth\Nutzerverwaltung;

/**
 * Der gemeinsame Schalter vor der lokalen Nutzerverwaltung (AI Brain #9007).
 */
beforeEach(function () {
    config()->set('ai-brain-bridge.user_admin', 'auto');
    config()->set('ai-brain-bridge.oauth.client_id', '');
    config()->set('ai-brain-bridge.oauth.client_secret', '');
    config()->set('ai-brain-bridge.base_url', 'https://brain.example.test');
});

it('bleibt ohne Anbindung lokal — ein Produkt ohne Brain traegt sich selbst', function () {
    expect(Nutzerverwaltung::angebunden())->toBeFalse();
    expect(Nutzerverwaltung::zentral())->toBeFalse();
    expect(Nutzerverwaltung::lokal())->toBeTrue();
});

it('sperrt zu, SOBALD das Produkt angebunden ist — ohne eine Zeile in der .env', function () {
    config()->set('ai-brain-bridge.oauth.client_id', 'abc');
    config()->set('ai-brain-bridge.oauth.client_secret', 'geheim');

    // Das ist der Kern der Entscheidung vom 28.10.2026: Haette die Vorgabe
    // 'lokal' gelautet, muesste in jeder Forge-Site eine Zeile nachgetragen
    // werden — und das eine Produkt, bei dem man sie vergisst, bliebe
    // UNBEMERKT auf der zweiten Wahrheit stehen.
    expect(Nutzerverwaltung::zentral())->toBeTrue();
});

it('laesst sich trotz Anbindung auf lokal stellen', function () {
    config()->set('ai-brain-bridge.oauth.client_id', 'abc');
    config()->set('ai-brain-bridge.oauth.client_secret', 'geheim');
    config()->set('ai-brain-bridge.user_admin', 'lokal');

    expect(Nutzerverwaltung::zentral())->toBeFalse();
});

it('laesst sich auch ohne Anbindung zusperren', function () {
    config()->set('ai-brain-bridge.user_admin', 'brain');

    expect(Nutzerverwaltung::zentral())->toBeTrue();
});

it('faellt bei einem unbekannten Wert auf auto zurueck, nicht auf zu', function () {
    config()->set('ai-brain-bridge.user_admin', 'vielleicht');

    // Ein Tippfehler darf nicht die strengere Bedeutung bekommen: Sonst
    // entscheidet ein verschriebenes Wort darueber, ob eine Maske zu ist.
    expect(Nutzerverwaltung::modus())->toBe('auto');
    expect(Nutzerverwaltung::zentral())->toBeFalse();
});

it('verweist auf die Benutzerseite in AI Brain, nicht auf die Startseite', function () {
    expect(Nutzerverwaltung::url())->toBe('https://brain.example.test/users');
    expect(Nutzerverwaltung::url('martin@example.test'))
        ->toBe('https://brain.example.test/users?suche=martin%40example.test');
});

it('nennt in der Abweisung den Weg, nicht nur das Nein', function () {
    config()->set('ai-brain-bridge.user_admin', 'brain');

    expect(Nutzerverwaltung::abweisung())->toContain('https://brain.example.test/users');
});

it('laesst Lesen durch und weist nur das Schreiben ab', function () {
    config()->set('ai-brain-bridge.user_admin', 'brain');

    Route::middleware(['web', 'ai-brain.user-admin'])->get('/test-nutzer', fn () => 'liste');
    Route::middleware(['web', 'ai-brain.user-admin'])->put('/test-nutzer', fn () => 'gespeichert');

    // Die Leseansicht MUSS erreichbar bleiben — sie ist der Ersatz fuer das
    // Formular, nicht dessen Wegfall.
    $this->get('/test-nutzer')->assertOk()->assertSee('liste');

    $this->from('/test-nutzer')->put('/test-nutzer')
        ->assertRedirect('/test-nutzer')
        ->assertSessionHas('error');
});

it('antwortet auf einen JSON-Aufruf mit 409 und einer lesbaren Meldung', function () {
    config()->set('ai-brain-bridge.user_admin', 'brain');

    Route::middleware(['web', 'ai-brain.user-admin'])->post('/test-nutzer', fn () => 'angelegt');

    // Ein `curl` auf die Route ist genau der Fall, fuer den es diesen Riegel
    // gibt: Ein ausgeblendetes Feld haette hier nichts verhindert.
    $this->postJson('/test-nutzer')
        ->assertStatus(409)
        ->assertJsonPath('ok', false);
});

it('laesst das Schreiben durch, solange die Verwaltung lokal ist', function () {
    Route::middleware(['web', 'ai-brain.user-admin'])->put('/test-nutzer', fn () => 'gespeichert');

    // Der Riegel darf in einer eigenstaendigen Installation nichts tun —
    // sonst koennte dort niemand mehr Rechte vergeben.
    $this->put('/test-nutzer')->assertOk()->assertSee('gespeichert');
});

it('nimmt die Rechtefelder heraus und sagt, welche — der Manager-Fall', function () {
    config()->set('ai-brain-bridge.user_admin', 'brain');

    // Im Manager liegen Rolle und Urlaubsanspruch auf DERSELBEN Route. Ein
    // pauschaler Riegel haette der Personalverwaltung ihre Arbeit weggenommen.
    [$gefiltert, $entfernt] = Nutzerverwaltung::ohneRechtefelder([
        'name' => 'Neu',
        'role' => 'administrator',
        'annual_vacation_days' => 30,
        'weekly_hours' => 40,
    ], ['name', 'email', 'role', 'password']);

    expect($gefiltert)->toBe(['annual_vacation_days' => 30, 'weekly_hours' => 40]);
    // Still weglassen waere die schlechteste Rueckmeldung von allen: „gespeichert"
    // lesen und die Rolle hat sich nicht geaendert.
    expect($entfernt)->toBe(['name', 'role']);
});

it('laesst lokal jedes Feld stehen', function () {
    config()->set('ai-brain-bridge.user_admin', 'lokal');

    [$gefiltert, $entfernt] = Nutzerverwaltung::ohneRechtefelder(
        ['role' => 'administrator'],
        ['role'],
    );

    expect($gefiltert)->toBe(['role' => 'administrator']);
    expect($entfernt)->toBe([]);
});
