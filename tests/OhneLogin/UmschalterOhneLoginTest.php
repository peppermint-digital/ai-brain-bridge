<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as Nutzer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Http\Controllers\SwitcherController;
use Peppermint\AiBrainBridge\Switcher\LokaleEintraege;

/**
 * Umschaltleiste OHNE Anmeldung ueber AI Brain (pm #10190).
 *
 * Bis hierher hing die Leiste an ZWEI Schaltern: AI_BRAIN_SWITCHER und
 * AI_BRAIN_LOGIN. Ein Produkt ohne den gemeinsamen Anmeldeweg bekam gar
 * keine Leiste — auch nicht seine EIGENEN Eintraege (Module, Administration,
 * Beta), die mit AI Brain nichts zu tun haben. In pm fehlte damit nach
 * v1.272.0 jeder Menueweg in die Administration.
 *
 * Jetzt: Die Leiste haengt nur an AI_BRAIN_SWITCHER. Die Eintraege des
 * Produkts kommen immer. Was AI Brain liefert (andere Systeme, Merkzettel,
 * Posteingang), kommt nur mit Anmeldeweg — ohne ihn fuehrten diese Knoepfe
 * auf Anmeldemasken.
 */
class OhneLoginNutzer extends Nutzer
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class OhneLoginEintraege implements LokaleEintraege
{
    public function fuer(Authenticatable $person, Request $anfrage): array
    {
        return [
            ['schluessel' => 'manager', 'name' => 'Manager', 'url' => '/tasks', 'kuerzel' => 'MA', 'aktuell' => true],
            ['schluessel' => 'administration', 'name' => 'Administration', 'url' => '/admin/users', 'kuerzel' => 'AD', 'symbol' => 'regler'],
        ];
    }
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
        });
    }

    Cache::flush();
    Http::fake(['*' => Http::response(['apps' => [['slug' => 'ai-brain', 'name' => 'AI Brain']], 'pins' => []])]);
    app()->bind(LokaleEintraege::class, OhneLoginEintraege::class);

    $this->person = OhneLoginNutzer::create(['name' => 'Bastian', 'email' => 'bastian@example.test']);
});

it('schreibt die Leiste ins Layout, auch ohne Anmeldung ueber AI Brain', function () {
    $this->actingAs($this->person);

    $markup = app(SwitcherController::class)->markup();

    expect($markup)->toContain('peppermint-app-switcher')
        ->and($markup)->toContain('/ai-brain/switcher/app-switcher.js')
        ->and($markup)->toContain('administration');
});

it('gibt dabei nur die Eintraege des Produkts mit — keine Liste aus AI Brain', function () {
    $this->actingAs($this->person);
    // Selbst eine bereitliegende Liste bleibt draussen: Ihre Knoepfe fuehrten
    // ohne Anmeldeweg auf Anmeldemasken.
    Cache::put('ai-brain-switcher:'.$this->person->getAuthIdentifier(), ['apps' => [['slug' => 'ai-brain']], 'pins' => []], 60);

    $markup = app(SwitcherController::class)->markup();

    expect($markup)->not->toContain('ai-brain&quot;')
        ->and($markup)->not->toContain('pin-endpoint');
});

it('beantwortet die Liste nur mit den eigenen Eintraegen und fragt AI Brain nicht', function () {
    $antwort = $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps');

    $antwort->assertOk();
    expect($antwort->json('apps'))->toBe([])
        ->and(array_column($antwort->json('lokal'), 'schluessel'))->toBe(['manager', 'administration']);
    Http::assertNothingSent();
});

it('liefert die Leiste selbst aus', function () {
    $this->actingAs($this->person)->get('/ai-brain/switcher/app-switcher.js')->assertOk();
});

it('registriert keinen Weg in den Anmeldefluss und keinen Merkzettel', function () {
    // `go` leitet ohne Sitzung in den Anmeldeweg — den es hier nicht gibt.
    expect(Route::has('ai-brain-bridge.switcher.go'))->toBeFalse()
        ->and(Route::has('ai-brain-bridge.switcher.pins'))->toBeFalse()
        ->and(Route::has('ai-brain-bridge.switcher.apps'))->toBeTrue();
});

it('schreibt ohne angemeldete Person weiterhin nichts', function () {
    expect(app(SwitcherController::class)->markup())->toBe('');
});

it('schreibt ohne Anmeldeweg nichts, wenn das Produkt keine eigenen Eintraege hat', function () {
    // Sonst stuende in einem solchen Produkt ploetzlich eine leere Leiste.
    app()->offsetUnset(LokaleEintraege::class);
    $this->actingAs($this->person);

    expect(app(SwitcherController::class)->markup())->toBe('');
});
