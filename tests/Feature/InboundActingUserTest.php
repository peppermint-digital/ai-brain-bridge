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
