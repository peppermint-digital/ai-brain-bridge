<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser;

/**
 * Gegenrichtung zur Acting-User-Delegation (#471): AI Brain ruft unseren
 * MCP-Server auf und behauptet pro Nachricht, wer gerade schreibt.
 *
 * Vorher baute das jedes Produkt selbst — mit dem Ergebnis, dass die Identität
 * entweder fehlte oder der Token-Besitzer war (bei Channel-Tokens also immer
 * dieselbe Person, egal wer tippt).
 */
beforeEach(function () {
    // Fake-Nutzer statt eines echten Models: das Paket kennt das Modell des
    // Produkts nicht, es geht nur durch den Resolver.
    $this->writer = new class implements Authenticatable
    {
        public string $email = 'chris@example.test';

        public function getAuthIdentifierName(): string
        {
            return 'email';
        }

        public function getAuthIdentifier(): string
        {
            return $this->email;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };

    Route::middleware(ResolveAiBrainActingUser::class)->get('/_test/who', fn () => response(
        Auth::user()?->email ?? 'niemand'
    ));

    // Die Frage, die `Auth::user()` NICHT beantwortet: Wurde die Person
    // nachgewiesen, oder haelt hier nur jemand das Token?
    Route::middleware(ResolveAiBrainActingUser::class)->get('/_test/nachgewiesen', fn (\Illuminate\Http\Request $r) => response(
        $r->attributes->get(ResolveAiBrainActingUser::ACTING_ASSERTED_ATTRIBUTE) === true ? 'ja' : 'nein'
    ));
});

/*
| Der Nachweis ist etwas anderes als die Anmeldung (#5946).
|
| In einem Produkt ist `$request->user()` NIE leer: Ohne Nachweis faellt die
| Anmeldung auf den Besitzer des Tokens zurueck — eine echte Person, naemlich
| die, die die Verbindung eingerichtet hat. Ein Aufruf ohne Absender sieht damit
| genauso aus wie einer von ihr.
|
| Gemessen am 21.09.2026 im Projekt-Manager: Ein Aufruf ueber den Komm-Layer
| OHNE angemeldete Person lieferte 200 Aufgaben, alle einer einzigen Person
| zugeordnet — dem Token-Besitzer. Der Riegel „keine Person → nichts" war damit
| wirkungslos, weil der Fall nie eintrat.
|
| Deshalb diese Markierung, und deshalb diese Tests: Sie halten fest, dass sie
| GENAU dann steht, wenn eine gueltig signierte Behauptung eine Person dieses
| Systems aufgeloest hat — und sonst nie.
*/

it('markiert einen gueltig signierten Schreiber als nachgewiesen', function () {
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    AiBrain::resolveInboundUserUsing(fn (string $email) => $email === 'chris@example.test' ? $this->writer : null);

    $this->get('/_test/nachgewiesen', signedHeaders('chris@example.test', 'shared-secret'))
        ->assertOk()
        ->assertSee('ja');
});

it('markiert einen Aufruf ohne Header NICHT als nachgewiesen', function () {
    // Der wichtigste Fall. Genau hier steht in einem Produkt der
    // Token-Besitzer in `Auth::user()` — und genau hier muss ein Werkzeug
    // erkennen koennen, dass niemand etwas behauptet hat.
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');

    $this->get('/_test/nachgewiesen')->assertOk()->assertSee('nein');
});

it('markiert eine gefaelschte Signatur NICHT als nachgewiesen', function () {
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    AiBrain::resolveInboundUserUsing(fn () => $this->writer);

    $this->get('/_test/nachgewiesen', signedHeaders('chris@example.test', 'shared-secret', 'sha256=deadbeef'))
        ->assertOk()
        ->assertSee('nein');
});

it('markiert nicht, wenn sich die behauptete Person hier gar nicht aufloesen laesst', function () {
    // Eine Behauptung ueber jemanden, den es hier nicht gibt, ist keine
    // Zuschreibung — auch wenn die Signatur stimmt. Der Aufruf laeuft weiter
    // (best-effort), aber er gilt nicht als nachgewiesen.
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    AiBrain::resolveInboundUserUsing(fn () => null);

    $this->get('/_test/nachgewiesen', signedHeaders('niemand@example.test', 'shared-secret'))
        ->assertOk()
        ->assertSee('nein');
});

it('markiert auch ohne konfiguriertes Secret, wenn die Person aufloest', function () {
    // Fail-safe wie beim Rest der Middleware: Ohne Secret gibt es nichts zu
    // pruefen, dann traegt allein das Token die Authentifizierung. Eine frische
    // Installation soll ohne Secret-Verteilung laufen — sonst waere der
    // Nachweis von einer Konfiguration abhaengig, die es noch nicht gibt.
    config()->set('ai-brain-bridge.events.secret', null);
    AiBrain::resolveInboundUserUsing(fn () => $this->writer);

    $this->get('/_test/nachgewiesen', [ResolveAiBrainActingUser::ACTING_USER_HEADER => 'chris@example.test'])
        ->assertOk()
        ->assertSee('ja');
});

function signedHeaders(string $email, string $secret, ?string $sig = null): array
{
    return [
        ResolveAiBrainActingUser::ACTING_USER_HEADER => $email,
        ResolveAiBrainActingUser::ACTING_SIG_HEADER => $sig ?? 'sha256='.hash_hmac('sha256', $email, $secret),
    ];
}

it('setzt den signierten Schreiber als handelnden Nutzer', function () {
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    AiBrain::resolveInboundUserUsing(fn (string $email) => $email === 'chris@example.test' ? $this->writer : null);

    $this->get('/_test/who', signedHeaders('chris@example.test', 'shared-secret'))
        ->assertOk()
        ->assertSee('chris@example.test');
});

it('verwirft eine gefälschte Signatur', function () {
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    AiBrain::resolveInboundUserUsing(fn () => $this->writer);

    $this->get('/_test/who', signedHeaders('chris@example.test', 'shared-secret', 'sha256=deadbeef'))
        ->assertOk()
        ->assertSee('niemand');
});

it('prüft ohne konfiguriertes Secret nicht (fail-safe wie in AI Brain)', function () {
    config()->set('ai-brain-bridge.events.secret', null);
    AiBrain::resolveInboundUserUsing(fn () => $this->writer);

    $this->get('/_test/who', [ResolveAiBrainActingUser::ACTING_USER_HEADER => 'chris@example.test'])
        ->assertOk()
        ->assertSee('chris@example.test');
});

it('blockiert den Aufruf nicht, wenn der Schreiber kein lokales Konto hat', function () {
    // Zuschreibung ist best-effort — sonst legt ein fehlendes Mapping laufende
    // Channels stumm.
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    AiBrain::resolveInboundUserUsing(fn () => null);

    $this->get('/_test/who', signedHeaders('niemand@example.test', 'shared-secret'))
        ->assertOk()
        ->assertSee('niemand');
});

it('lässt Aufrufe ohne Header unverändert durch', function () {
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');

    $this->get('/_test/who')->assertOk()->assertSee('niemand');
});

it('stellt die Herkunft (Channel) fuer das Audit bereit', function () {
    // Rein zur Zuschreibung: „ueber welchen Weg kam das?" — auch ohne Menschen
    // dahinter. Bewusst KEIN Rechte-Input, sonst muesste jedes Produkt die
    // Channel-Verwaltung nachbauen.
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');

    Route::middleware(ResolveAiBrainActingUser::class)->get('/_test/channel', fn () => response(
        AiBrain::inboundChannel() ?? 'unbekannt'
    ));

    $this->get('/_test/channel', [ResolveAiBrainActingUser::CHANNEL_HEADER => 'outreach-mail'])
        ->assertOk()
        ->assertSee('outreach-mail');
});

it('meldet keine Herkunft, wenn der Aufruf nicht von AI Brain kommt', function () {
    Route::middleware(ResolveAiBrainActingUser::class)->get('/_test/channel', fn () => response(
        AiBrain::inboundChannel() ?? 'unbekannt'
    ));

    $this->get('/_test/channel')->assertOk()->assertSee('unbekannt');
});
