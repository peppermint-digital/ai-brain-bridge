<?php

use Illuminate\Http\Client\Request as HttpAnfrage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Bestehende Favoriten aus AI Brain verlustfrei ins Produkt (pm #10219).
 *
 * Bis hierher lagen sie in AI Brain. Gelesen wird ueber die Verbindung — je
 * verbundener Person, in ihrem Namen. Uebernommen werden nur Adressen DIESES
 * Produkts; die uebrigen gehoeren dem System, auf das sie zeigen.
 */
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

    $this->allein = FavoritenNutzer::create(['name' => 'Allein', 'email' => 'allein@example.test']);
    $this->eins = FavoritenNutzer::create([
        'name' => 'Eins', 'email' => 'eins@example.test', 'ai_brain_user_id' => 1, 'ai_brain_email' => 'eins@brain.test',
    ]);
    $this->fuenf = FavoritenNutzer::create([
        'name' => 'Fuenf', 'email' => 'fuenf@example.test', 'ai_brain_user_id' => 5,
    ]);

    // Was AI Brain je Person antwortet — entschieden an der Acting-Adresse.
    $this->pinsJePerson = [
        'eins@brain.test' => [
            ['id' => 11, 'label' => 'Aufgaben', 'url' => 'https://pm.test/tasks', 'symbol' => 'haken'],
            ['id' => 12, 'label' => 'Projekt', 'url' => 'https://pm.test/projects/4', 'symbol' => null],
            ['id' => 13, 'label' => 'Brain', 'url' => 'https://brain.test/notes', 'symbol' => 'buch'],
        ],
        'fuenf@example.test' => [
            ['id' => 21, 'label' => 'Kalender', 'url' => 'https://pm.test/calendar', 'symbol' => 'kalender'],
        ],
    ];

    Http::fake(function (HttpAnfrage $anfrage) {
        if (str_contains($anfrage->url(), '/oauth/token')) {
            return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
        }

        $wer = $anfrage->header('X-AI-Brain-Acting-User')[0] ?? null;

        if ($wer === ($this->kaputtFuer ?? null)) {
            return Http::response([], 500);
        }

        return Http::response(['apps' => [], 'pins' => $this->pinsJePerson[$wer] ?? []]);
    });
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/ai-brain-favoriten'));
});

it('zaehlt im Trockenlauf und schreibt nichts', function () {
    $this->artisan('ai-brain:favoriten-uebernehmen')
        ->expectsOutputToContain('Trockenlauf')
        ->expectsOutputToContain('2 verbundene Personen, 3 Favoriten dieses Produkts, 3 neu, 0 schon da')
        ->assertSuccessful();

    expect(DB::table('switcher_pins')->count())->toBe(0);
});

it('uebernimmt nur Adressen dieses Produkts, mit Name, Symbol und Reihenfolge', function () {
    $this->artisan('ai-brain:favoriten-uebernehmen --ausfuehren')
        ->expectsOutputToContain('Gegenprobe: Quelle 3 = Ziel 3')
        ->assertSuccessful();

    $eins = DB::table('switcher_pins')->where('user_id', $this->eins->id)->orderBy('position')->get();
    expect($eins->pluck('url')->all())->toBe(['https://pm.test/tasks', 'https://pm.test/projects/4'])
        ->and($eins->pluck('label')->all())->toBe(['Aufgaben', 'Projekt'])
        ->and($eins->pluck('symbol')->all())->toBe(['haken', null]);

    expect(DB::table('switcher_pins')->where('user_id', $this->fuenf->id)->pluck('url')->all())
        ->toBe(['https://pm.test/calendar']);
});

it('fragt AI Brain nur fuer verbundene Personen', function () {
    $this->artisan('ai-brain:favoriten-uebernehmen')->assertSuccessful();

    Http::assertNotSent(fn (HttpAnfrage $anfrage): bool => ($anfrage->header('X-AI-Brain-Acting-User')[0] ?? null) === 'allein@example.test');
    Http::assertSent(fn (HttpAnfrage $anfrage): bool => ($anfrage->header('X-AI-Brain-Acting-User')[0] ?? null) === 'eins@brain.test');
});

it('legt beim zweiten Lauf nichts doppelt an', function () {
    $this->artisan('ai-brain:favoriten-uebernehmen --ausfuehren')->assertSuccessful();

    $this->artisan('ai-brain:favoriten-uebernehmen --ausfuehren')
        ->expectsOutputToContain('0 neu, 3 schon da')
        ->expectsOutputToContain('Gegenprobe: Quelle 3 = Ziel 3')
        ->assertSuccessful();

    expect(DB::table('switcher_pins')->count())->toBe(3);
});

it('nimmt eine Uebernahme zurueck, ohne spaeter angelegte Favoriten anzufassen', function () {
    $this->artisan('ai-brain:favoriten-uebernehmen --ausfuehren')->assertSuccessful();
    $datei = collect(File::files(storage_path('app/ai-brain-favoriten')))->first()->getPathname();

    DB::table('switcher_pins')->insert([
        'user_id' => $this->allein->id, 'url' => 'https://pm.test/neu', 'url_hash' => hash('sha256', 'https://pm.test/neu'),
        'label' => 'Spaeter', 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('ai-brain:favoriten-uebernehmen --zuruecknehmen='.$datei)
        ->expectsOutputToContain('3 entfernt')
        ->assertSuccessful();

    expect(DB::table('switcher_pins')->pluck('label')->all())->toBe(['Spaeter']);
});

it('bricht ab und schreibt nichts, wenn AI Brain fuer eine Person nicht antwortet', function () {
    // Die ERSTE Person antwortet, die zweite nicht: Auch die Favoriten der
    // ersten werden dann nicht geschrieben.
    $this->kaputtFuer = 'fuenf@example.test';

    $this->artisan('ai-brain:favoriten-uebernehmen --ausfuehren')
        ->expectsOutputToContain('nicht erreichbar')
        ->assertFailed();

    expect(DB::table('switcher_pins')->count())->toBe(0);
});
