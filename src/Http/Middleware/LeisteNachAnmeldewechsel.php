<?php

namespace Peppermint\AiBrainBridge\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nach einem Wechsel der angemeldeten Person die Seite ganz neu laden (pm Bug #1621).
 *
 * ## Wozu
 *
 * Die Umschaltleiste steht im Blade-Layout, ausserhalb von `@inertia`, und
 * `@aiBrainSwitcher` schreibt sie nur fuer Angemeldete. Inertia rendert das
 * Layout aber nur beim ersten Aufruf. Anmelden und Abmelden laufen als
 * Inertia-Anfrage, und deren Weiterleitung folgt der Browser per XHR. Das
 * Layout bleibt dann das der Seite davor:
 *
 * - nach dem Anmelden fehlt die Leiste, bis man neu laedt,
 * - nach dem Abmelden steht die Leiste der abgemeldeten Person ueber der
 *   Anmeldemaske.
 *
 * Bastian (26.11.2026): „Die Topbar, wenn ich mich neu einlogge [...] ist die
 * Topbar nicht sichtbar. Erst wenn ich die Seite einmal neu lade, danach ist
 * die Topbar dann sichtbar."
 *
 * ## Was sie tut
 *
 * Sie merkt sich, wer vor der Anfrage angemeldet war, und vergleicht danach.
 * Hat eine Inertia-Anfrage die Person gewechselt und antwortet mit einer
 * Weiterleitung, wird daraus ein Inertia-Seitenwechsel (409 mit
 * `X-Inertia-Location`). Der Browser laedt das Ziel dann vollstaendig, und
 * das Layout entsteht neu.
 *
 * ## Warum hier und nicht an jedem Anmeldeweg
 *
 * Ein Produkt hat viele Stellen, an denen die Person wechselt: Passwort,
 * zweiter Faktor, Einladung, Einrichtung nach dem Zuruecksetzen, Abmelden,
 * Deaktivierung, Impersonate. Jede einzeln umzustellen haette der naechste
 * neue Weg wieder vergessen. Der Vergleich vorher/nachher trifft alle.
 *
 * ## Was sie NICHT anfasst
 *
 * - Anfragen ohne `X-Inertia`: ein gewoehnliches Formular, der Rueckweg aus
 *   AI Brain (`/auth/brain/callback`), der Dev-Login. Dort laedt der Browser
 *   ohnehin die ganze Seite, die 302 bleibt unveraendert.
 * - Antworten, die keine Weiterleitung sind.
 * - Produkte mit abgeschalteter Leiste: Ohne Leiste gibt es nichts neu zu
 *   zeichnen.
 *
 * ## Einhaengen
 *
 * Wie die anderen Riegel des Pakets nur ein KUERZEL, das Produkt haengt es
 * selbst an seine `web`-Gruppe. Dort ist zu sehen, warum eine Anmeldung voll
 * neu laedt:
 *
 *     $middleware->web(append: ['ai-brain.leiste-nach-anmeldewechsel']);
 */
class LeisteNachAnmeldewechsel
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('ai-brain-bridge.switcher.enabled') || ! $request->header('X-Inertia')) {
            return $next($request);
        }

        $guard = Auth::guard((string) config('ai-brain-bridge.login.guard', 'web'));
        $vorher = $guard->id();

        $response = $next($request);

        if (! $response instanceof RedirectResponse || $guard->id() === $vorher) {
            return $response;
        }

        $seitenwechsel = new Response('', Response::HTTP_CONFLICT, [
            'X-Inertia-Location' => $response->getTargetUrl(),
        ]);

        // Cookies, die direkt an der Weiterleitung haengen (etwa „angemeldet
        // bleiben"), muessen mit. Das Sitzungs-Cookie setzt StartSession
        // weiter aussen ohnehin auf die neue Antwort.
        foreach ($response->headers->getCookies() as $cookie) {
            $seitenwechsel->headers->setCookie($cookie);
        }

        return $seitenwechsel;
    }
}
