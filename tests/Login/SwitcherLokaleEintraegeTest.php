<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Http\Controllers\SwitcherController;
use Peppermint\AiBrainBridge\Switcher\LokaleEintraege;

/**
 * Eintraege, die das Produkt der Umschaltleiste beisteuert (die Module des
 * Managers, #10082) — neben den Systemen aus AI Brain.
 *
 * Zwei Zusagen stehen hier im Mittelpunkt: Ohne Bindung bleibt alles wie
 * vorher, und die Eintraege landen nie im Zwischenspeicher je Person.
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

    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/users/me/apps*' => Http::response(['apps' => [
            ['slug' => 'ai-brain', 'name' => 'AI Brain'],
            ['slug' => 'peppermint-manager', 'name' => 'Peppermint Manager', 'aktuell' => true],
        ], 'pins' => []]),
        '*' => Http::response([], 500),
    ]);
});

/** Ein Produkt, dessen Eintraege sich im Test aendern lassen. */
class ProbeEintraege implements LokaleEintraege
{
    /** @var list<array<string, mixed>> */
    public static array $eintraege = [];

    public static ?Request $zuletzt = null;

    public function fuer(Authenticatable $person, Request $anfrage): array
    {
        self::$zuletzt = $anfrage;

        return self::$eintraege;
    }
}

function modulEintrag(string $schluessel, bool $aktuell = false): array
{
    return ['schluessel' => $schluessel, 'name' => ucfirst($schluessel), 'url' => '/'.$schluessel, 'kuerzel' => strtoupper($schluessel[0]), 'aktuell' => $aktuell];
}

function bindeEintraege(array $eintraege): void
{
    ProbeEintraege::$eintraege = $eintraege;
    app()->bind(LokaleEintraege::class, ProbeEintraege::class);
}

it('aendert ohne Bindung weder Antwort noch Layout', function () {
    $antwort = $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps');

    $antwort->assertOk();
    expect($antwort->json())->toHaveKeys(['apps', 'pins'])
        ->not->toHaveKey('lokal');

    $markup = app(SwitcherController::class)->markup();

    expect($markup)->not->toContain('lokal');
});

it('legt die Eintraege des Produkts neben die Systeme aus AI Brain', function () {
    bindeEintraege([modulEintrag('manager', aktuell: true), modulEintrag('personal')]);

    $antwort = $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps');

    $antwort->assertOk();
    expect(collect($antwort->json('apps'))->pluck('slug')->all())->toBe(['ai-brain', 'peppermint-manager'])
        ->and(collect($antwort->json('lokal'))->pluck('schluessel')->all())->toBe(['manager', 'personal'])
        ->and($antwort->json('lokal.0.aktuell'))->toBeTrue();

    // Das Produkt bekommt die Anfrage — damit kann es das aktive Modul an der
    // aktuellen Seite ablesen.
    expect(ProbeEintraege::$zuletzt)->not->toBeNull();
});

it('legt die Eintraege nie in den Zwischenspeicher — ein entzogenes Modul fehlt beim naechsten Aufruf', function () {
    bindeEintraege([modulEintrag('manager'), modulEintrag('personal')]);

    $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps')
        ->assertJsonPath('lokal.1.schluessel', 'personal');

    // Das Recht wird entzogen. Die Liste von AI Brain liegt noch im
    // Zwischenspeicher — sie wird NICHT neu geholt.
    bindeEintraege([modulEintrag('manager')]);

    $zweite = $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps');

    expect(collect($zweite->json('lokal'))->pluck('schluessel')->all())->toBe(['manager'])
        ->and(collect($zweite->json('apps'))->pluck('slug')->all())->toBe(['ai-brain', 'peppermint-manager']);

    Http::assertSentCount(2); // Token + EIN Abruf der Systeme fuer beide Aufrufe

    $imSpeicher = Cache::get('ai-brain-switcher:'.$this->person->getAuthIdentifier());
    expect($imSpeicher)->toBeArray()->not->toHaveKey('lokal');
});

it('schreibt die Eintraege sofort ins Layout, auch wenn AI Brain noch nichts geliefert hat', function () {
    bindeEintraege([modulEintrag('manager', aktuell: true), modulEintrag('personal')]);
    $this->actingAs($this->person);

    $markup = app(SwitcherController::class)->markup();

    // Kein Weg zu AI Brain beim Rendern — und trotzdem stehen die Module da.
    Http::assertNothingSent();

    preg_match('/ apps="([^"]*)"/', $markup, $treffer);
    $nutzlast = json_decode(html_entity_decode($treffer[1] ?? ''), true);

    // Nur `lokal`, kein leeres `apps`: Die Leiste nimmt die Systeme dann aus
    // ihrem eigenen Speicher, statt sie fuer leer zu halten.
    expect($nutzlast)->toBe(['lokal' => [modulEintrag('manager', aktuell: true), modulEintrag('personal')]]);
});

it('schreibt die Eintraege zur bereitstehenden Liste ins Layout — frisch, nicht aus dem Speicher', function () {
    bindeEintraege([modulEintrag('manager'), modulEintrag('personal')]);
    $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps')->assertOk();

    bindeEintraege([modulEintrag('personal')]);
    $markup = app(SwitcherController::class)->markup();

    preg_match('/ apps="([^"]*)"/', $markup, $treffer);
    $nutzlast = json_decode(html_entity_decode($treffer[1] ?? ''), true);

    expect(collect($nutzlast['apps'])->pluck('slug')->all())->toBe(['ai-brain', 'peppermint-manager'])
        ->and(collect($nutzlast['lokal'])->pluck('schluessel')->all())->toBe(['personal']);
});

it('reisst die Leiste nicht mit, wenn das Produkt beim Ermitteln wirft', function () {
    app()->bind(LokaleEintraege::class, fn () => new class implements LokaleEintraege
    {
        public function fuer(Authenticatable $person, Request $anfrage): array
        {
            throw new RuntimeException('kaputt');
        }
    });

    $this->actingAs($this->person)->getJson('/ai-brain/switcher/apps')
        ->assertOk()
        ->assertJsonPath('lokal', [])
        ->assertJsonPath('apps.0.slug', 'ai-brain');
});

it('gibt ohne Anmeldung auch mit Bindung nichts heraus', function () {
    bindeEintraege([modulEintrag('manager')]);

    $this->getJson('/ai-brain/switcher/apps')->assertOk()->assertExactJson(['apps' => [], 'pins' => []]);

    expect(app(SwitcherController::class)->markup())->toBe('');
});
