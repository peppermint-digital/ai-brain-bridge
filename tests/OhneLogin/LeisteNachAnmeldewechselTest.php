<?php

use Illuminate\Foundation\Auth\User as Nutzer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Nach einem Wechsel der angemeldeten Person laedt die Seite voll (pm Bug #1621).
 *
 * Die Leiste steht im Blade-Layout, das Inertia nach einer XHR-Weiterleitung
 * nicht neu schreibt. Gemessen im Manager: nach Passwort + Code fehlte sie auf
 * /dashboard, nach dem Abmelden stand sie ueber der Anmeldemaske.
 */
class AnmeldewechselNutzer extends Nutzer
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
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

    config()->set('auth.providers.users.model', AnmeldewechselNutzer::class);

    $this->person = AnmeldewechselNutzer::create(['name' => 'Bastian', 'email' => 'bastian@example.test']);
    $this->andere = AnmeldewechselNutzer::create(['name' => 'Frieda', 'email' => 'frieda@example.test']);

    $kette = ['web', 'ai-brain.leiste-nach-anmeldewechsel'];

    Route::middleware($kette)->post('/test/anmelden', function (Request $request) {
        Auth::guard('web')->loginUsingId($request->integer('id'));

        return redirect('/dashboard')->withCookie(cookie('angemeldet-bleiben', 'ja'));
    });

    Route::middleware($kette)->post('/test/abmelden', function () {
        Auth::guard('web')->logout();

        return redirect('/login');
    });

    Route::middleware($kette)->post('/test/abgewiesen', fn () => redirect('/login'));

    Route::middleware($kette)->post('/test/anmelden-ohne-weiterleitung', function (Request $request) {
        Auth::guard('web')->loginUsingId($request->integer('id'));

        return response('angemeldet');
    });
});

it('laedt nach dem Anmelden per Inertia die ganze Seite', function () {
    $antwort = $this->post('/test/anmelden', ['id' => $this->person->id], ['X-Inertia' => 'true']);

    $antwort->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/dashboard'))
        // Die Cookies der Weiterleitung gehen nicht verloren.
        ->assertCookie('angemeldet-bleiben');

    $this->assertAuthenticatedAs($this->person);
});

it('laedt nach dem Abmelden per Inertia die ganze Seite', function () {
    $this->actingAs($this->person);

    $this->post('/test/abmelden', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/login'));

    $this->assertGuest();
});

it('laedt neu, wenn eine andere Person uebernimmt', function () {
    $this->actingAs($this->person);

    $this->post('/test/anmelden', ['id' => $this->andere->id], ['X-Inertia' => 'true'])
        ->assertStatus(409);
});

it('laesst eine gewoehnliche Anfrage ohne Inertia unveraendert', function () {
    // Formular ohne Inertia, Rueckweg aus AI Brain, Dev-Login: Der Browser
    // laedt dort ohnehin ganz.
    $this->post('/test/anmelden', ['id' => $this->person->id])
        ->assertStatus(302)
        ->assertRedirect('/dashboard');
});

it('laesst eine Inertia-Anfrage ohne Wechsel unveraendert', function () {
    $this->post('/test/abgewiesen', [], ['X-Inertia' => 'true'])
        ->assertStatus(302)
        ->assertRedirect('/login');

    $this->actingAs($this->person);

    $this->post('/test/anmelden', ['id' => $this->person->id], ['X-Inertia' => 'true'])
        ->assertStatus(302);
});

it('laesst eine Antwort ohne Weiterleitung unveraendert', function () {
    $this->post('/test/anmelden-ohne-weiterleitung', ['id' => $this->person->id], ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertSee('angemeldet');
});

it('tut ohne Leiste nichts', function () {
    config()->set('ai-brain-bridge.switcher.enabled', false);

    $this->post('/test/anmelden', ['id' => $this->person->id], ['X-Inertia' => 'true'])
        ->assertStatus(302);
});
