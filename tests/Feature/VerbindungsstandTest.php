<?php

use Illuminate\Foundation\Auth\User as Nutzer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Verbund\Bruecke;
use Peppermint\AiBrainBridge\Verbund\Kontomeldung;
use Peppermint\AiBrainBridge\Verbund\Verbindungsstand;

/**
 * „Verbunden" im Personen-Menue (pm #10265).
 *
 * Bastian: „Es sollten im Manager und im AI-Brain klar erkennbar sein, wenn
 * beide Systeme existieren und verbunden sind." Gibt es das andere System
 * nicht, zeigt die Oberflaeche nichts — die Liste ist dann leer.
 */
class StandNutzer extends Nutzer
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::create('users', function ($table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->unique();
        $table->unsignedInteger('ai_brain_user_id')->nullable();
    });

    Bruecke::vergessen();
    $this->verbunden = StandNutzer::create(['name' => 'V', 'email' => 'v@example.test', 'ai_brain_user_id' => 7]);
    $this->allein = StandNutzer::create(['name' => 'A', 'email' => 'a@example.test']);
});

afterEach(fn () => Bruecke::vergessen());

it('meldet eine verbundene Person als verbunden, mit Link zu den Einstellungen', function () {
    Route::get('/settings/ai-brain', fn () => 'ok')->name('settings.ai-brain');
    Route::getRoutes()->refreshNameLookups();

    expect(app(Verbindungsstand::class)->fuer($this->verbunden))->toBe([[
        'system' => 'AI Brain',
        'verbunden' => true,
        'text' => 'Mit AI Brain verbunden',
        'url' => '/settings/ai-brain',
    ]]);
});

it('bietet einer unverbundenen Person das Verbinden an', function () {
    $stand = app(Verbindungsstand::class)->fuer($this->allein);

    expect($stand)->toHaveCount(1)
        ->and($stand[0]['verbunden'])->toBeFalse()
        ->and($stand[0]['text'])->toBe('Mit AI Brain verbinden')
        // Ohne Einstellungsseite im Produkt: kein Link statt eines toten.
        ->and($stand[0]['url'])->toBeNull();
});

it('zeigt nichts, wenn es kein anderes System gibt', function () {
    app()->bind(AiBrainManager::class, fn () => throw new RuntimeException('nicht eingerichtet'));

    expect(app(Verbindungsstand::class)->fuer($this->verbunden))->toBe([]);
});

it('zeigt ohne angemeldete Person nichts', function () {
    expect(app(Verbindungsstand::class)->fuer(null))->toBe([]);
});

it('nimmt Name und Route aus der Konfiguration', function () {
    config(['ai-brain-bridge.verbindung.anzeige_name' => 'Hub', 'ai-brain-bridge.verbindung.einstellungen_route' => 'meins']);
    Route::get('/meins', fn () => 'ok')->name('meins');
    Route::getRoutes()->refreshNameLookups();

    expect(app(Verbindungsstand::class)->fuer($this->verbunden)[0])
        ->toMatchArray(['system' => 'Hub', 'text' => 'Mit Hub verbunden', 'url' => '/meins']);
});

it('meldet AI Brain die Produktkennung und die verbundene Person, getrennt als null', function () {
    expect(Kontomeldung::nutzlast($this->verbunden))->toBe(['product_user_id' => (string) $this->verbunden->id, 'brain_user_id' => '7'])
        ->and(Kontomeldung::nutzlast($this->allein))->toBe(['product_user_id' => (string) $this->allein->id, 'brain_user_id' => null]);
});

it('meldet denselben Stand in einem Aufruf nur einmal', function () {
    Illuminate\Support\Facades\Bus::fake();

    Kontomeldung::melden($this->verbunden);
    Kontomeldung::melden($this->verbunden->fresh());
    $this->verbunden->forceFill(['ai_brain_user_id' => null])->save();
    Kontomeldung::melden($this->verbunden);

    Illuminate\Support\Facades\Bus::assertDispatchedAfterResponseTimes(Illuminate\Queue\CallQueuedClosure::class, 2);
});
