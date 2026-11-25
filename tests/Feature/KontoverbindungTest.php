<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as Nutzer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Kontoverbindung;

/**
 * „Konto mit AI Brain verbunden" — EIN Schalter fuer alle Pakete (pm #10219).
 *
 * Bastian: „Wer keinen AI-Brain nutzt, der hat die Funktion einfach nicht. Und
 * sobald ein Benutzer-Account mit einem AI-Brain-Account verknuepft ist, kann
 * man auch oben einen Link dafuer benutzen."
 */
class KontoNutzer extends Nutzer
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
        $table->string('ai_brain_email')->nullable();
    });

    $this->verbunden = KontoNutzer::create([
        'name' => 'Verbunden', 'email' => 'hier@example.test',
        'ai_brain_user_id' => 7, 'ai_brain_email' => 'dort@example.test',
    ]);
    $this->allein = KontoNutzer::create(['name' => 'Allein', 'email' => 'allein@example.test']);
});

it('nennt eine Person mit AI-Brain-Konto verbunden, eine ohne nicht', function () {
    $verbindung = app(Kontoverbindung::class);

    expect($verbindung->istVerbunden($this->verbunden))->toBeTrue()
        ->and($verbindung->istVerbunden($this->allein))->toBeFalse()
        ->and($verbindung->istVerbunden(null))->toBeFalse();
});

it('beantwortet die Frage als Gate, damit andere Pakete die Bruecke nicht kennen muessen', function () {
    expect(Gate::forUser($this->verbunden)->allows('ai-brain.verbunden'))->toBeTrue()
        ->and(Gate::forUser($this->allein)->allows('ai-brain.verbunden'))->toBeFalse();

    // Ohne angemeldete Person: nicht verbunden.
    expect(Gate::allows('ai-brain.verbunden'))->toBeFalse();

    $this->actingAs($this->verbunden);
    expect(Gate::allows('ai-brain.verbunden'))->toBeTrue();
});

it('bietet denselben Schalter ueber die Fassade an, vorgabemaessig fuer die angemeldete Person', function () {
    expect(AiBrain::istVerbunden($this->verbunden))->toBeTrue()
        ->and(AiBrain::istVerbunden($this->allein))->toBeFalse()
        ->and(AiBrain::istVerbunden())->toBeFalse();

    $this->actingAs($this->verbunden);
    expect(AiBrain::istVerbunden())->toBeTrue();
});

it('tritt fuer eine verbundene Person mit der Adresse ihres AI-Brain-Kontos auf', function () {
    $verbindung = app(Kontoverbindung::class);

    expect($verbindung->adresse($this->verbunden))->toBe('dort@example.test')
        ->and($verbindung->adresse($this->allein))->toBe('allein@example.test')
        ->and($verbindung->adresse(null))->toBeNull();
});

it('nimmt diese Adresse auch als handelnde Person, wenn das Produkt nichts anderes sagt', function () {
    $this->actingAs($this->verbunden);
    expect(AiBrain::actingUserEmail())->toBe('dort@example.test');

    $this->actingAs($this->allein);
    expect(AiBrain::actingUserEmail())->toBe('allein@example.test');
});

it('folgt einer Regel, die das Produkt selbst bindet', function () {
    app()->instance(Kontoverbindung::class, new class implements Kontoverbindung
    {
        public function istVerbunden(?Authenticatable $person): bool
        {
            return $person?->name === 'Allein';
        }

        public function adresse(?Authenticatable $person): ?string
        {
            return $person === null ? null : 'regel@example.test';
        }
    });

    expect(Gate::forUser($this->allein)->allows('ai-brain.verbunden'))->toBeTrue()
        ->and(Gate::forUser($this->verbunden)->allows('ai-brain.verbunden'))->toBeFalse()
        ->and(AiBrain::istVerbunden($this->allein))->toBeTrue();
});

it('haelt eine Person in einem Produkt ohne Verbindungs-Spalte fuer nicht verbunden', function () {
    Schema::drop('users');
    Schema::create('users', function ($table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->unique();
    });
    $person = KontoNutzer::create(['name' => 'Ohne', 'email' => 'ohne@example.test']);

    expect(AiBrain::istVerbunden($person))->toBeFalse()
        ->and(app(Kontoverbindung::class)->adresse($person))->toBe('ohne@example.test');
});
