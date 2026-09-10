<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
