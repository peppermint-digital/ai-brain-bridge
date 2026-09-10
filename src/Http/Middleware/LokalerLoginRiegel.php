<?php

namespace Peppermint\AiBrainBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Den lokalen Anmeldeweg auf einen Notzugang verengen (AI Brain #5346).
 *
 * ## Wozu
 *
 * Solange man sich hier auch mit lokalem Passwort anmelden kann, gilt die
 * Passwort- und MFA-Richtlinie von AI Brain nur fuer die, die freiwillig den
 * gemeinsamen Weg nehmen. Ist der lokale Weg zu, gibt es genau einen Eingang —
 * und Brains Richtlinie ist damit automatisch die Richtlinie hier. Ohne
 * Nachbauen, ohne Synchronisieren.
 *
 * ## Warum verengen und nicht schliessen
 *
 * Ein vollstaendig geschlossener lokaler Weg heisst: Faellt AI Brain aus, kommt
 * NIEMAND mehr herein — auch nicht, um es zu reparieren.
 *
 * Der naheliegende Ausweg waere ein zweiter, eigens gebauter Notfallweg. Das
 * waere eine bewusst gebaute Hintertuer: neuer Code, neue Angriffsflaeche, und
 * genau die Stelle, an der ein Fehler alles kostet.
 *
 * Der lokale Login ist dagegen vorhanden und erprobt. Ihn fuer wenige Menschen
 * offenzulassen ist deshalb der kleinere Eingriff als ihn zu schliessen und
 * daneben etwas Neues aufzumachen.
 *
 * ## Was das von den Ausnahmen verlangt
 *
 * Wer hier stehen bleibt, ist der schwaechste Punkt der ganzen Kette: Fuer ihn
 * gilt Brains MFA-Pflicht nicht. Diese Konten brauchen im Produkt selbst ein
 * starkes Passwort und eigenes MFA. Die Liste gehoert kurz — je laenger sie
 * ist, desto weniger bringt das Zusperren.
 *
 * ## Was der Riegel NICHT anfasst
 *
 * Den Weg ueber AI Brain. Er greift ausschliesslich an der lokalen
 * Anmeldemaske; wer ueber `/auth/brain/callback` hereinkommt, ist davon
 * unberuehrt. Ein Riegel, der beide Wege trifft, waere eine Aussperrung.
 */
class LokalerLoginRiegel
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('ai-brain-bridge.login.local', 'an') !== 'notzugang') {
            return $next($request);
        }

        // Nur die Anmeldung selbst, und nur das Abschicken. Ein GET auf die
        // Maske bleibt erreichbar — dort steht der Knopf zu AI Brain.
        if (! $request->isMethod('post') || ! $this->istAnmeldung($request)) {
            return $next($request);
        }

        $email = mb_strtolower(trim((string) $request->input('email')));

        if ($email !== '' && in_array($email, $this->notzugaenge(), true)) {
            Log::info('Lokale Anmeldung ueber den Notzugang', ['email' => $email]);

            return $next($request);
        }

        // Sichtbar machen, nicht nur verweigern: Wer hier auflaeuft, soll den
        // Grund lesen und den richtigen Knopf finden — sonst haelt er sein
        // Passwort fuer falsch und setzt es zurueck.
        Log::info('Lokale Anmeldung abgewiesen — der Weg fuehrt ueber AI Brain', [
            'email' => $email !== '' ? $email : '(keine Angabe)',
        ]);

        return back()
            ->withInput($request->except('password'))
            ->withErrors(['email' => 'Die Anmeldung mit Passwort ist hier abgeschaltet. '
                .'Bitte „Mit AI Brain anmelden" benutzen.']);
    }

    /**
     * Ist das die Anmeldemaske dieses Produkts?
     *
     * Der PFAD entscheidet — aber er wird aus dem benannten Weg abgeleitet,
     * nicht fest verdrahtet. Ein Produkt, das seine Anmeldung unter
     * `/anmelden` fuehrt, ist damit genauso erfasst.
     *
     * ## Warum nicht ueber den Routen-Namen
     *
     * Weil die POST-Route in allen drei Produkten GAR KEINEN Namen hat — nur
     * das GET auf die Maske heisst `login`. Ein `routeIs('login')` waere hier
     * also immer falsch gewesen, und der Riegel haette stillschweigend nichts
     * getan: eine Sicherheitsfunktion, die aussieht, als sei sie an.
     *
     * Aufgefallen ist es nur, weil ein Test das Abweisen geprueft hat statt
     * das Durchlassen.
     */
    protected function istAnmeldung(Request $request): bool
    {
        $name = (string) config('ai-brain-bridge.login.failure_route', 'login');

        if (! Route::has($name)) {
            return false;
        }

        $pfad = parse_url(route($name, [], false), PHP_URL_PATH);

        return trim((string) $pfad, '/') === trim($request->getPathInfo(), '/');
    }

    /**
     * @return list<string>
     */
    protected function notzugaenge(): array
    {
        $roh = config('ai-brain-bridge.login.local_except', []);

        if (is_string($roh)) {
            $roh = explode(',', $roh);
        }

        return array_values(array_filter(array_map(
            fn ($e): string => mb_strtolower(trim((string) $e)),
            (array) $roh,
        )));
    }
}
