<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as Authenticatable2;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Auth\BrainLogin;

/**
 * Konto mit AI Brain verbinden (pm #10162).
 *
 * Das Produkt meldet seine Personen selbst an. Damit KI-Funktionen trotzdem
 * im Namen der richtigen Person laufen, verbindet eine angemeldete Person ihr
 * Konto mit ihrem AI-Brain-Konto — über denselben OAuth-Client und dieselbe
 * Rückruf-Adresse wie die Anmeldung, aber ohne anzumelden.
 */
class VerbindenTestUser extends Authenticatable2
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
        $table->string('password')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedBigInteger('brain_id')->nullable();
    });

    BrainLogin::resolveUsing(null);
    BrainLogin::verbindeUsing(function (Authenticatable $user, array $person): ?string {
        $user->forceFill(['brain_id' => $person['id']])->save();

        return null;
    });
});

afterEach(function () {
    BrainLogin::resolveUsing(null);
    BrainLogin::verbindeUsing(null);
});

function brainNenntPerson(int $id, string $email): void
{
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok']),
        'brain.test/api/v1/me' => Http::response(['id' => $id, 'name' => 'Person', 'email' => $email]),
    ]);
}

it('registriert Start und Rückruf, aber keinen Anmeldestart', function () {
    expect(Route::has('ai-brain-bridge.login.connect'))->toBeTrue()
        ->and(Route::has('ai-brain-bridge.login.callback'))->toBeTrue()
        ->and(Route::has('ai-brain-bridge.login.redirect'))->toBeFalse();
});

it('schickt eine angemeldete Person mit Prüfwort zu AI Brain', function () {
    $person = VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);

    $antwort = $this->actingAs($person)->get('/auth/brain/verbinden');

    $antwort->assertRedirectContains('https://brain.test/oauth/authorize');
    $antwort->assertRedirectContains('client_id=login-client');
    $antwort->assertRedirectContains('code_challenge_method=S256');

    expect(session('ai_brain_login.zweck'))->toBe('verbinden')
        ->and(session('ai_brain_login.verbinder'))->toBe($person->id);
});

it('lässt niemanden ohne Anmeldung verbinden', function () {
    $this->get('/auth/brain/verbinden')->assertForbidden();

    expect(session('ai_brain_login.zweck'))->toBeNull();
});

it('verbindet die angemeldete Person, auch wenn die Adressen verschieden sind', function () {
    $person = VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);
    brainNenntPerson(42, 'ganz-andere@example.com');

    $this->actingAs($person)
        ->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v', 'ai_brain_login.zweck' => 'verbinden', 'ai_brain_login.verbinder' => $person->id])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect('/einstellungen/ai-brain')
        ->assertSessionHas('status');

    expect($person->fresh()->brain_id)->toBe(42)
        ->and(auth()->id())->toBe($person->id);
});

it('verbindet nicht, wenn das Produkt ablehnt, und nennt den Grund', function () {
    $person = VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);
    BrainLogin::verbindeUsing(fn () => 'Dieses AI-Brain-Konto ist schon mit einer anderen Person verbunden.');
    brainNenntPerson(42, 'a@example.com');

    $this->actingAs($person)
        ->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v', 'ai_brain_login.zweck' => 'verbinden', 'ai_brain_login.verbinder' => $person->id])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect('/einstellungen/ai-brain')
        ->assertSessionHasErrors(['ai_brain' => 'Dieses AI-Brain-Konto ist schon mit einer anderen Person verbunden.']);

    expect($person->fresh()->brain_id)->toBeNull();
});

it('verbindet nicht, wenn das Produkt keinen Verbinder hat', function () {
    $person = VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);
    BrainLogin::verbindeUsing(null);
    brainNenntPerson(42, 'a@example.com');

    $this->actingAs($person)
        ->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v', 'ai_brain_login.zweck' => 'verbinden', 'ai_brain_login.verbinder' => $person->id])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertSessionHasErrors('ai_brain');

    expect($person->fresh()->brain_id)->toBeNull();
});

it('verbindet nicht, wenn inzwischen jemand anderes angemeldet ist', function () {
    $erste = VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);
    $zweite = VerbindenTestUser::create(['name' => 'B', 'email' => 'b@example.com']);
    brainNenntPerson(42, 'a@example.com');

    $this->actingAs($zweite)
        ->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v', 'ai_brain_login.zweck' => 'verbinden', 'ai_brain_login.verbinder' => $erste->id])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertSessionHasErrors('ai_brain');

    expect($erste->fresh()->brain_id)->toBeNull()
        ->and($zweite->fresh()->brain_id)->toBeNull();
});

it('verbindet nicht bei falschem Zustand', function () {
    $person = VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);
    brainNenntPerson(42, 'a@example.com');

    $this->actingAs($person)
        ->withSession(['ai_brain_login.state' => 'echt', 'ai_brain_login.verifier' => 'v', 'ai_brain_login.zweck' => 'verbinden', 'ai_brain_login.verbinder' => $person->id])
        ->get('/auth/brain/callback?code=abc&state=gefaelscht')
        ->assertSessionHasErrors('ai_brain');

    expect($person->fresh()->brain_id)->toBeNull();
});

it('meldet über den Rückruf niemanden an, solange die gemeinsame Anmeldung aus ist', function () {
    VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);
    brainNenntPerson(42, 'a@example.com');

    $this->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v'])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect();

    expect(auth()->check())->toBeFalse();
    Http::assertNothingSent();
});

it('meldet AI Brain die neue Verbindung als Ereignis (pm #10265)', function () {
    config(['ai-brain-bridge.verbindung.spalte' => 'brain_id', 'ai-brain-bridge.source' => 'produkt-test']);
    $person = VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);
    brainNenntPerson(42, 'a@example.com');
    Http::fake(['brain.test/api/v1/events' => Http::response(['ok' => true], 202)]);

    $this->actingAs($person)
        ->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v', 'ai_brain_login.zweck' => 'verbinden', 'ai_brain_login.verbinder' => $person->id])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect('/einstellungen/ai-brain');

    Http::assertSent(fn ($anfrage) => str_ends_with($anfrage->url(), '/api/v1/events')
        && $anfrage['type'] === 'konto.verbindung'
        && $anfrage['payload'] === ['product_user_id' => (string) $person->id, 'brain_user_id' => '42']);
});

it('meldet nichts, wenn das Verbinden scheitert', function () {
    $person = VerbindenTestUser::create(['name' => 'A', 'email' => 'a@example.com']);
    BrainLogin::verbindeUsing(fn () => 'nein');
    brainNenntPerson(42, 'a@example.com');

    $this->actingAs($person)
        ->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v', 'ai_brain_login.zweck' => 'verbinden', 'ai_brain_login.verbinder' => $person->id])
        ->get('/auth/brain/callback?code=abc&state=s');

    Http::assertNotSent(fn ($anfrage) => str_ends_with($anfrage->url(), '/api/v1/events'));
});
