<?php

namespace Peppermint\AiBrainBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Auth\Nutzerverwaltung;
use Symfony\Component\HttpFoundation\Response;

/**
 * Der Server-Riegel vor der lokalen Nutzerverwaltung (AI Brain #9007).
 *
 * Läuft die Verwaltung über AI Brain ({@see Nutzerverwaltung::zentral()}), geht
 * hier kein schreibender Zugriff mehr durch. Lesen bleibt erlaubt — die Maske
 * soll weiter zeigen, was gilt, nur eben nicht mehr ändern.
 *
 * ## Warum ein Riegel und nicht nur ausgeblendete Felder
 *
 * Weil ein ausgeblendetes Feld kein Riegel ist. Die Route bleibt erreichbar,
 * ein `curl` genügt, und ein bestehender Verweis im Browserverlauf kommt an.
 * Das Wiki „Ein Riegel vor der Tuer sagt nichts ueber die Fenster — Rechte
 * pruefen, wo sie wirken" hat genau diesen Fall zum Anlass.
 *
 * Der Riegel ist dabei die ÄUSSERE Schicht. Die innere gehört in die Policy des
 * Produkts (`UserPolicy::create()` & Co.), damit auch ein Weg, der nicht über
 * diese Route läuft — Konsolenbefehl, anderer Controller — nicht daran
 * vorbeikommt. Beides, und zwar absichtlich doppelt.
 *
 * ## Das Produkt sagt, wo
 *
 * Die Middleware errät keine Pfade. Sie wird dort gesetzt, wo das Produkt seine
 * Nutzerverwaltung führt — und nur das Produkt weiss, welche Routen das sind.
 * Im Manager liegen Rechte und Mitarbeiterdaten auf DERSELBEN Route
 * (`admin/users/{user}` trägt Rolle und Urlaubsanspruch); dort ist der Schnitt
 * feiner und läuft über die Felder, nicht über den Weg. Ein Riegel, der dort
 * pauschal zusperrte, nähme der Personalverwaltung ihre Arbeit weg.
 */
class NutzerverwaltungRiegel
{
    /**
     * Welche Verben als „ändern" gelten. GET und HEAD bleiben offen, damit die
     * Leseansicht erreichbar ist.
     */
    private const LESEND = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Nutzerverwaltung::lokal()) {
            return $next($request);
        }

        if (in_array($request->method(), self::LESEND, true)) {
            return $next($request);
        }

        Log::info('Lokale Nutzerverwaltung abgewiesen — der Weg führt über AI Brain', [
            'pfad' => $request->path(),
            'verb' => $request->method(),
            'durch' => $request->user()->email ?? '(nicht angemeldet)',
        ]);

        $meldung = Nutzerverwaltung::abweisung();

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $meldung], 409);
        }

        // Zurueck mit einer lesbaren Begruendung statt 403: Wer hier auflaeuft,
        // hat nichts falsch gemacht — er stand nur auf der alten Maske.
        return back()->with('error', $meldung);
    }
}
