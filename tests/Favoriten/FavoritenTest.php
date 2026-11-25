<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as Nutzer;
use Illuminate\Http\Client\Request as HttpAnfrage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Http\Controllers\SwitcherController;
use Peppermint\AiBrainBridge\Switcher\LokaleEintraege;

/**
 * Favoriten im Produkt selbst (pm #10219).
 *
 * Bastian: „im top menue fehlen jetzt die favoriten und wenn ai brain da ist
 * ein link zum ai brain" — und: „jedes system haelt wieder seine eigenen
 * daten". Die Favoriten liegen deshalb im Produkt, fuer jede Person. Was AI
 * Brain beisteuert (der Weg zu AI Brain), gibt es nur mit verbundenem Konto.
 */
class FavoritenNutzer extends Nutzer
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class FavoritenEintraege implements LokaleEintraege
{
    public function fuer(Authenticatable $person, Request $anfrage): array
    {
        return [
            ['schluessel' => 'manager', 'name' => 'Manager', 'url' => '/tasks', 'kuerzel' => 'MA', 'aktuell' => true],
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
            $table->unsignedInteger('ai_brain_user_id')->nullable();
            $table->string('ai_brain_email')->nullable();
        });
    }

    $this->artisan('migrate')->assertSuccessful();

    Cache::flush();
    app()->bind(LokaleEintraege::class, FavoritenEintraege::class);

    $this->allein = FavoritenNutzer::create(['name' => 'Allein', 'email' => 'allein@example.test']);
    $this->verbunden = FavoritenNutzer::create([
        'name' => 'Verbunden', 'email' => 'hier@example.test',
        'ai_brain_user_id' => 5, 'ai_brain_email' => 'dort@example.test',
    ]);

    $this->hubAntwortet = function (array $apps, array $pins = []): void {
        Http::fake([
            'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'brain.test/api/v1/users/me/apps*' => Http::response(['apps' => $apps, 'pins' => $pins]),
            '*' => Http::response([], 500),
        ]);
    };
});

function favorit(array $daten = []): array
{
    return array_merge(['url' => 'https://pm.test/projects/4', 'label' => 'Projekt 4'], $daten);
}

it('legt einen Favoriten im Produkt an, auch ohne verbundenes Konto, und fragt AI Brain nicht', function () {
    Http::fake();

    $antwort = $this->actingAs($this->allein)->postJson('/ai-brain/switcher/pins', favorit(['symbol' => 'stern']));

    $antwort->assertOk()
        ->assertJsonPath('pin.label', 'Projekt 4')
        ->assertJsonPath('pin.url', 'https://pm.test/projects/4')
        ->assertJsonPath('pin.slug', 'peppermint-manager')
        ->assertJsonPath('pin.symbol', 'stern');
    expect($antwort->json('pin.id'))->toBeInt()
        ->and($antwort->json('pin.farbe'))->toStartWith('#');
    expect(DB::table('switcher_pins')->where('user_id', $this->allein->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('nimmt nur Adressen dieses Produkts an', function () {
    $this->actingAs($this->allein)
        ->postJson('/ai-brain/switcher/pins', favorit(['url' => 'https://boese.example/pm.test/x']))
        ->assertStatus(422);

    $this->actingAs($this->allein)
        ->postJson('/ai-brain/switcher/pins', favorit(['url' => 'https://pm.test.boese.example/x']))
        ->assertStatus(422);

    expect(DB::table('switcher_pins')->count())->toBe(0);
});

it('legt dieselbe Adresse nicht zweimal ab', function () {
    $erste = $this->actingAs($this->allein)->postJson('/ai-brain/switcher/pins', favorit())->json('pin.id');
    $zweite = $this->actingAs($this->allein)->postJson('/ai-brain/switcher/pins', favorit(['label' => 'Anders']))->json('pin.id');

    expect($zweite)->toBe($erste)
        ->and(DB::table('switcher_pins')->count())->toBe(1);
});

it('haelt die Grenze von zwoelf Favoriten', function () {
    foreach (range(1, 12) as $nummer) {
        $this->actingAs($this->allein)
            ->postJson('/ai-brain/switcher/pins', favorit(['url' => 'https://pm.test/p/'.$nummer]))
            ->assertOk();
    }

    $this->actingAs($this->allein)
        ->postJson('/ai-brain/switcher/pins', favorit(['url' => 'https://pm.test/p/13']))
        ->assertStatus(422);
});

it('verwirft ein unbekanntes Symbol und kuerzt einen langen Namen', function () {
    $antwort = $this->actingAs($this->allein)
        ->postJson('/ai-brain/switcher/pins', favorit(['symbol' => '<script>', 'label' => '  '.str_repeat('x', 200).'  ']));

    $antwort->assertOk()->assertJsonPath('pin.symbol', null);
    expect(mb_strlen($antwort->json('pin.label')))->toBe(80);
});

it('benennt nur eigene Favoriten um', function () {
    $id = $this->actingAs($this->allein)->postJson('/ai-brain/switcher/pins', favorit())->json('pin.id');

    $this->actingAs($this->verbunden)
        ->patchJson('/ai-brain/switcher/pins', ['id' => $id, 'label' => 'Fremd'])
        ->assertNotFound();

    $this->actingAs($this->allein)
        ->patchJson('/ai-brain/switcher/pins', ['id' => $id, 'label' => 'Neu', 'symbol' => 'uhr'])
        ->assertOk()
        ->assertJsonPath('pin.label', 'Neu')
        ->assertJsonPath('pin.symbol', 'uhr');
});

it('entfernt nur eigene Favoriten', function () {
    $id = $this->actingAs($this->allein)->postJson('/ai-brain/switcher/pins', favorit())->json('pin.id');

    $this->actingAs($this->verbunden)->deleteJson('/ai-brain/switcher/pins', ['id' => $id])->assertOk();
    expect(DB::table('switcher_pins')->count())->toBe(1);

    $this->actingAs($this->allein)->deleteJson('/ai-brain/switcher/pins', ['id' => $id])->assertOk();
    expect(DB::table('switcher_pins')->count())->toBe(0);
});

it('weist ohne angemeldete Person ab', function () {
    $this->postJson('/ai-brain/switcher/pins', favorit())->assertUnauthorized();
});

it('zeigt einer Person ohne Verbindung ihre Favoriten, aber keinen Weg zu AI Brain', function () {
    Http::fake();
    $this->actingAs($this->allein)->postJson('/ai-brain/switcher/pins', favorit());

    $antwort = $this->actingAs($this->allein)->getJson('/ai-brain/switcher/apps');

    $antwort->assertOk();
    expect($antwort->json('apps'))->toBe([])
        ->and(array_column($antwort->json('pins'), 'label'))->toBe(['Projekt 4'])
        ->and(array_column($antwort->json('lokal'), 'schluessel'))->toBe(['manager']);
    Http::assertNothingSent();
});

it('zeigt einer verbundenen Person AI Brain — ueber die Verbindung, mit ihrer AI-Brain-Adresse', function () {
    ($this->hubAntwortet)(
        [['slug' => 'ai-brain', 'name' => 'AI Brain', 'url' => 'https://brain.test'], ['slug' => 'peppermint-manager', 'aktuell' => true]],
        [['id' => 99, 'label' => 'Alter Pin in AI Brain', 'url' => 'https://pm.test/alt']],
    );
    $this->actingAs($this->verbunden)->postJson('/ai-brain/switcher/pins', favorit());

    $antwort = $this->actingAs($this->verbunden)->getJson('/ai-brain/switcher/apps');

    expect(array_column($antwort->json('apps'), 'slug'))->toBe(['ai-brain', 'peppermint-manager'])
        // Die Favoriten sind die des Produkts — nicht die aus AI Brain.
        ->and(array_column($antwort->json('pins'), 'label'))->toBe(['Projekt 4']);

    Http::assertSent(fn (HttpAnfrage $anfrage): bool => str_contains($anfrage->url(), '/api/v1/users/me/apps')
        && $anfrage->header('X-AI-Brain-Acting-User') === ['dort@example.test']);
});

it('zeigt die Favoriten weiter, wenn AI Brain nicht antwortet', function () {
    Http::fake(['*' => Http::response([], 500)]);
    $this->actingAs($this->verbunden)->postJson('/ai-brain/switcher/pins', favorit());

    $antwort = $this->actingAs($this->verbunden)->getJson('/ai-brain/switcher/apps');

    $antwort->assertOk();
    expect($antwort->json('apps'))->toBe([])
        ->and(array_column($antwort->json('pins'), 'label'))->toBe(['Projekt 4']);
});

it('schreibt die Leiste mit Stern und den Favoriten ins Layout, ohne zu AI Brain zu gehen', function () {
    Http::fake();
    $this->actingAs($this->allein)->postJson('/ai-brain/switcher/pins', favorit());

    $markup = app(SwitcherController::class)->markup();

    expect($markup)->toContain('pin-endpoint="'.e(route('ai-brain-bridge.switcher.pins')).'"')
        ->and($markup)->toContain('Projekt 4')
        // Ausdruecklich leere Systeme: Sonst naehme die Leiste eine alte Liste
        // aus dem Sitzungsspeicher des Browsers — womoeglich mit AI Brain.
        ->and($markup)->toContain('&quot;apps&quot;:[]');
    Http::assertNothingSent();
});

it('nimmt fuer eine verbundene Person die bereitliegende Liste mit, fuer eine andere nie', function () {
    $liste = ['apps' => [['slug' => 'ai-brain', 'name' => 'AI Brain']], 'pins' => []];
    Cache::put('ai-brain-switcher:'.$this->verbunden->id, $liste, 60);
    Cache::put('ai-brain-switcher:'.$this->allein->id, $liste, 60);

    $this->actingAs($this->verbunden);
    expect(app(SwitcherController::class)->markup())->toContain('ai-brain&quot;');

    $this->actingAs($this->allein);
    expect(app(SwitcherController::class)->markup())->not->toContain('ai-brain&quot;');
});

it('registriert den Weg fuer die Favoriten, aber keinen in den Anmeldefluss', function () {
    expect(Route::has('ai-brain-bridge.switcher.pins'))->toBeTrue()
        ->and(Route::has('ai-brain-bridge.switcher.go'))->toBeFalse();
});

it('loescht die Favoriten einer Person mit ihr', function () {
    $this->actingAs($this->allein)->postJson('/ai-brain/switcher/pins', favorit());

    $this->allein->delete();

    expect(DB::table('switcher_pins')->count())->toBe(0);
});

it('laesst jede Seite laden, wenn die Tabelle noch fehlt', function () {
    // Zwischen Deploy und `migrate` darf die Leiste fehlen — die Seite nicht.
    Schema::drop('switcher_pins');
    $this->actingAs($this->allein);

    expect(app(SwitcherController::class)->markup())->toContain('peppermint-app-switcher');
    $this->getJson('/ai-brain/switcher/apps')->assertOk()->assertJsonPath('pins', []);
});
