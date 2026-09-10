<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Den lokalen Anmeldeweg auf einen Notzugang verengen (AI Brain #5346).
 *
 * Der Kern dieser Datei sind die drei Dinge, die der Riegel NICHT tun darf:
 * sich von selbst schliessen, den Weg ueber AI Brain treffen, oder den
 * Notzugang aussperren. Jedes davon waere eine Aussperrung statt eines
 * Sicherheitsgewinns.
 */
beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    // Eine Anmeldemaske, wie sie ein Produkt WIRKLICH hat: Nur das GET traegt
    // einen Namen, das POST nicht. Genau daran ist die erste Fassung des
    // Riegels gescheitert — sie suchte nach dem Namen des POST.
    Route::middleware('web')->group(function () {
        Route::get('/login', fn () => 'maske')->name('login');
        Route::post('/login', fn () => 'angemeldet')->middleware('ai-brain.local-login');
    });

    // Routen, die nach dem Booten entstehen, stehen noch nicht in der
    // Namensliste — `Route::has()` waere sonst falsch. Im Betrieb passiert das
    // beim Laden der Routendatei von selbst.
    Route::getRoutes()->refreshNameLookups();
});

it('laesst alles durch, solange nichts eingestellt ist', function () {
    // VORGABE IST OFFEN. Ein Riegel, der sich beim Einspielen des Pakets von
    // selbst schliesst, sperrt beim ersten Deploy alle aus.
    expect(config('ai-brain-bridge.login.local'))->toBe('an');

    $this->post('/login', ['email' => 'irgendwer@example.test', 'password' => 'x'])
        ->assertOk();
});

it('weist die lokale Anmeldung ab, wenn sie verengt ist', function () {
    config()->set('ai-brain-bridge.login.local', 'notzugang');
    config()->set('ai-brain-bridge.login.local_except', 'chef@example.test');

    $this->from('/login')
        ->post('/login', ['email' => 'irgendwer@example.test', 'password' => 'x'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
});

it('laesst den Notzugang herein', function () {
    // Faellt AI Brain aus, muss jemand hereinkommen — um zu arbeiten oder zu
    // reparieren.
    config()->set('ai-brain-bridge.login.local', 'notzugang');
    config()->set('ai-brain-bridge.login.local_except', 'chef@example.test');

    $this->post('/login', ['email' => 'chef@example.test', 'password' => 'x'])
        ->assertOk();
});

it('nimmt Gross- und Kleinschreibung nicht krumm', function () {
    // Sonst steht jemand vor seiner eigenen Tuer, weil er die Adresse anders
    // getippt hat als sie in der Konfiguration steht.
    config()->set('ai-brain-bridge.login.local', 'notzugang');
    config()->set('ai-brain-bridge.login.local_except', ' Chef@Example.test , zweite@example.test ');

    $this->post('/login', ['email' => 'chef@EXAMPLE.test', 'password' => 'x'])->assertOk();
    $this->post('/login', ['email' => 'zweite@example.test', 'password' => 'x'])->assertOk();
});

it('laesst die Anmeldemaske selbst erreichbar', function () {
    // Dort steht der Knopf zu AI Brain. Eine gesperrte Maske haette niemandem
    // gesagt, wo es langgeht.
    config()->set('ai-brain-bridge.login.local', 'notzugang');
    config()->set('ai-brain-bridge.login.local_except', '');

    $this->get('/login')->assertOk();
});

it('fasst den Weg ueber AI Brain nicht an', function () {
    // Ein Riegel, der beide Wege trifft, waere eine Aussperrung.
    config()->set('ai-brain-bridge.login.local', 'notzugang');
    config()->set('ai-brain-bridge.login.local_except', '');

    $this->get('/auth/brain/redirect')->assertRedirectContains('https://brain.test/oauth/authorize');
});

it('sperrt bei leerer Ausnahmeliste jeden lokalen Versuch', function () {
    config()->set('ai-brain-bridge.login.local', 'notzugang');
    config()->set('ai-brain-bridge.login.local_except', '');

    $this->from('/login')
        ->post('/login', ['email' => 'chef@example.test', 'password' => 'x'])
        ->assertSessionHasErrors('email');
});
