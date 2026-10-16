<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Http\Controllers\SwitcherController;

/**
 * Umschaltleiste zwischen den Peppermint-Systemen (AI Brain #5281).
 *
 * Zwei Dinge stehen hier im Mittelpunkt: dass beim Umschalten niemand eine
 * Anmeldemaske sieht — und dass die Leiste ausfaellt, ohne die Seite
 * mitzureissen, wenn AI Brain nicht antwortet.
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

    Cache::flush();

    $this->person = LoginTestUser::create([
        'name' => 'Bastian',
        'email' => 'bastian@example.test',
    ]);
});

it('geht mit bestehender Sitzung ohne Umweg ins Ziel', function () {
    // Der Gewinn gegenueber „immer durch den Anmeldefluss": kein OAuth, wenn
    // die Sitzung ohnehin steht.
    Http::fake(['*' => Http::response([], 500)]);

    $this->actingAs($this->person)
        ->get('/auth/brain/go?ziel=/projekte')
        ->assertRedirect('/projekte');

    Http::assertNothingSent();
});

it('fuehrt ohne Sitzung durch den Anmeldeweg, mit dem Ziel im Gepaeck', function () {
    $this->get('/auth/brain/go?ziel=/projekte')
        ->assertRedirectContains('/auth/brain/redirect')
        ->assertRedirectContains('ziel=%2Fprojekte');
});

it('nimmt kein fremdes Ziel an', function () {
    // Sonst waere der Umschaltknopf eine Weiterleitungs-Rampe.
    $this->actingAs($this->person)
        ->get('/auth/brain/go?ziel=https://boese.example/phish')
        ->assertRedirect('/dashboard');

    $this->actingAs($this->person)
        ->get('/auth/brain/go?ziel=//boese.example')
        ->assertRedirect('/dashboard');
});

it('faellt ohne Ziel auf die Startseite des Produkts zurueck', function () {
    // Wohin, entscheidet das Produkt — nicht AI Brain.
    $this->actingAs($this->person)
        ->get('/auth/brain/go')
        ->assertRedirect('/dashboard');
});

it('holt die Systeme bei AI Brain und reicht sie durch', function () {
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/users/me/apps*' => Http::response(['apps' => [
            ['slug' => 'ai-brain', 'name' => 'AI Brain'],
            ['slug' => 'peppermint-crm', 'name' => 'Peppermint CRM'],
        ]]),
        '*' => Http::response([], 500),
    ]);

    $antwort = $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps');

    $antwort->assertOk();
    expect(collect($antwort->json('apps'))->pluck('slug')->all())
        ->toBe(['ai-brain', 'peppermint-crm']);
});

it('nennt AI Brain die handelnde Person, statt sie raten zu lassen', function () {
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/users/me/apps*' => Http::response(['apps' => []]),
        '*' => Http::response([], 500),
    ]);

    $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps')->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/users/me/apps')
        && $request->header('X-AI-Brain-Acting-User')[0] === 'bastian@example.test');
});

it('liefert eine leere Liste, wenn AI Brain nicht antwortet', function () {
    // Die Leiste ist Beiwerk. Faellt der Hub aus, erscheint sie nicht — die
    // Seite laedt trotzdem.
    Http::fake(['*' => Http::response([], 503)]);

    $this->actingAs($this->person)
        ->getJson('/ai-brain/switcher/apps')
        ->assertOk()
        ->assertJson(['apps' => []]);
});

it('gibt ohne Anmeldung nichts heraus', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->getJson('/ai-brain/switcher/apps')->assertOk()->assertJson(['apps' => []]);

    Http::assertNothingSent();
});

it('liefert die Leiste selbst aus dem Paket aus', function () {
    $antwort = $this->actingAs($this->person)->get('/ai-brain/switcher/app-switcher.js');

    $antwort->assertOk();
    expect($antwort->headers->get('Content-Type'))->toContain('javascript');
});

it('schreibt ohne Anmeldung nichts ins Layout', function () {
    $markup = app(SwitcherController::class)->markup();

    expect($markup)->toBe('');
});

it('schreibt mit Anmeldung Skript und Element ins Layout', function () {
    $this->actingAs($this->person);

    $markup = app(SwitcherController::class)->markup();

    expect($markup)->toContain('peppermint-app-switcher')
        ->and($markup)->toContain('/ai-brain/switcher/app-switcher.js')
        ->and($markup)->toContain('endpoint=');
});

it('gibt die Liste im Layout mit, wenn sie schon bereitsteht', function () {
    // Sonst poppt die Leiste beim Ankommen nach — genau in dem Moment, in dem
    // jemand gerade das System gewechselt hat und hinsieht.
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/users/me/apps*' => Http::response(['apps' => [
            ['slug' => 'ai-brain', 'name' => 'AI Brain'],
            ['slug' => 'peppermint-crm', 'name' => 'Peppermint CRM'],
        ]]),
        '*' => Http::response([], 500),
    ]);

    $this->actingAs($this->person);

    // Der Endpunkt fuellt den Zwischenspeicher — das ist der Weg, auf dem die
    // Liste hierher kommt.
    $this->getJson('/ai-brain/switcher/apps')->assertOk();

    $markup = app(SwitcherController::class)->markup();

    expect($markup)->toContain('apps=')
        ->and($markup)->toContain('peppermint-crm');
});

it('zieht beim Rendern einer Seite NIEMALS zu AI Brain los', function () {
    // Der Fehler, den ein Nutzer als „jetzt dauert es laenger" gemeldet hat:
    // Ein Aufruf zum Hub an dieser Stelle macht JEDE Seite dieses Produkts
    // davon abhaengig, dass Brain schnell antwortet — und beim ersten Aufruf
    // nach Ablauf des Zwischenspeichers wartet ein Mensch darauf, obwohl er
    // die Leiste vielleicht gar nicht benutzt.
    Http::fake(['*' => Http::response([], 500)]);

    Cache::flush();
    $this->actingAs($this->person);

    $markup = app(SwitcherController::class)->markup();

    Http::assertNothingSent();

    // Ohne bereitstehende Liste faellt das Attribut weg — die Leiste holt sie
    // nach dem Laden selbst.
    expect($markup)->toContain('peppermint-app-switcher')
        ->and($markup)->not->toContain('apps=');
});

/* ------------------------------------------------ die Postfach-Kachel */

it('schreibt kein Postfach ins Layout, wenn das Produkt keinen Posteingang hat', function () {
    // Der Normalfall fuer ein Produkt ohne peppermint/notifications. Ein
    // Symbol, das auf einen 404 fuehrt, ist schlimmer als keines.
    $this->actingAs($this->person);

    expect(app(SwitcherController::class)->markup())->not->toContain('postfach=');
});

it('schreibt das Postfach ins Layout, sobald beide Routen stehen', function () {
    Route::get('notifications/inbox', fn () => '')->name('notifications.inbox');
    Route::get('api/inbox/unread-count', fn () => '')->name('inbox.api.unread-count');

    $this->actingAs($this->person);

    $markup = app(SwitcherController::class)->markup();

    expect($markup)->toContain('postfach="http://localhost/notifications/inbox"')
        ->and($markup)->toContain('postfach-zaehler="http://localhost/api/inbox/unread-count"');
});

it('verlangt BEIDE Routen', function () {
    // Nur die Seite ohne den Zaehler ergaebe eine Kachel, die nie ein
    // Abzeichen bekommt — und damit aussieht, als waere nie etwas da.
    Route::get('notifications/inbox', fn () => '')->name('notifications.inbox');

    $this->actingAs($this->person);

    expect(app(SwitcherController::class)->markup())->not->toContain('postfach=');
});

it('beantwortet dieselbe Frage fuer das Produkt wie fuer die Leiste', function () {
    // Das Produkt laesst seine eigene Glocke genau dann weg. Liefen die
    // beiden Antworten auseinander, waere der schlimme Fall nicht die
    // doppelte Anzeige, sondern gar keine.
    expect(SwitcherController::zeigtPostfach())->toBeFalse();

    Route::get('notifications/inbox', fn () => '')->name('notifications.inbox');
    Route::get('api/inbox/unread-count', fn () => '')->name('inbox.api.unread-count');

    // Nur im Test noetig: Die Namensliste des Routers ist zu diesem Zeitpunkt
    // schon gebaut, und `Route::has` liest sie. Im Betrieb stehen alle Routen
    // beim Hochfahren.
    Route::getRoutes()->refreshNameLookups();

    expect(SwitcherController::zeigtPostfach())->toBeTrue();

    config()->set('ai-brain-bridge.switcher.enabled', false);

    expect(SwitcherController::zeigtPostfach())->toBeFalse();
});
