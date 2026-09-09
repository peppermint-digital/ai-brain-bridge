<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Auth\BrainLogin;

/**
 * „Mit AI Brain anmelden" (AI Brain #5266).
 */
class LoginTestUser extends Authenticatable
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
    });

    BrainLogin::resolveUsing(null);
});

afterEach(function () {
    BrainLogin::resolveUsing(null);
});

it('schickt mit Prüfwort und Zustand zu AI Brain', function () {
    $antwort = $this->get('/auth/brain/redirect');

    $antwort->assertRedirectContains('https://brain.test/oauth/authorize');
    $antwort->assertRedirectContains('code_challenge_method=S256');
    $antwort->assertRedirectContains('scope=identity');

    expect(session('ai_brain_login.verifier'))->toBeString()
        ->and(session('ai_brain_login.state'))->toBeString();
});

it('lässt niemanden herein, wenn der Zustand nicht stimmt', function () {
    $this->withSession(['ai_brain_login.state' => 'echt', 'ai_brain_login.verifier' => 'x'])
        ->get('/auth/brain/callback?code=abc&state=gefaelscht')
        ->assertRedirect();

    expect(auth()->check())->toBeFalse();
});

it('meldet eine bekannte Person an', function () {
    LoginTestUser::create(['name' => 'Alt', 'email' => 'chris@peppermint-digital.de']);

    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok']),
        'brain.test/api/v1/me' => Http::response(['id' => 7, 'name' => 'Chris', 'email' => 'chris@peppermint-digital.de']),
    ]);

    $this->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v'])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect('/dashboard');

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('chris@peppermint-digital.de');
});

it('legt für eine unbekannte Person kein Konto an', function () {
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok']),
        'brain.test/api/v1/me' => Http::response(['id' => 9, 'name' => 'Fremd', 'email' => 'fremd@example.com']),
    ]);

    $this->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v'])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect();

    expect(auth()->check())->toBeFalse()
        ->and(LoginTestUser::where('email', 'fremd@example.com')->exists())->toBeFalse();
});

it('achtet den entzogenen Zugang des Produkts', function () {
    LoginTestUser::create(['name' => 'Weg', 'email' => 'weg@peppermint-digital.de', 'is_active' => false]);

    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok']),
        'brain.test/api/v1/me' => Http::response(['id' => 3, 'name' => 'Weg', 'email' => 'weg@peppermint-digital.de']),
    ]);

    $this->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v'])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect();

    expect(auth()->check())->toBeFalse();
});

it('lässt niemanden herein, wenn AI Brain den Code nicht eintauscht', function () {
    Http::fake([
        'brain.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $this->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v'])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect();

    expect(auth()->check())->toBeFalse();
});

it('lässt das Produkt die Zuordnung selbst bestimmen', function () {
    $eigener = LoginTestUser::create(['name' => 'Anders', 'email' => 'anders@peppermint-digital.de']);

    BrainLogin::resolveUsing(fn (array $person) => $eigener);

    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok']),
        'brain.test/api/v1/me' => Http::response(['id' => 1, 'name' => 'X', 'email' => 'gibtsnicht@example.com']),
    ]);

    $this->withSession(['ai_brain_login.state' => 's', 'ai_brain_login.verifier' => 'v'])
        ->get('/auth/brain/callback?code=abc&state=s')
        ->assertRedirect('/dashboard');

    expect(auth()->user()->email)->toBe('anders@peppermint-digital.de');
});
