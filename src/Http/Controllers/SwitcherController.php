<?php

namespace Peppermint\AiBrainBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Config\BridgeConfig;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Umschaltleiste im Produkt (AI Brain #5281).
 *
 * Zwei Aufgaben, die zusammengehoeren:
 *
 * 1. `apps()` — welche Systeme stehen dieser Person offen? Beantwortet AI Brain
 *    aus dem Verzeichnis; das Produkt fragt nur und reicht durch.
 * 2. `go()` — der Weg hinein, ohne Anmeldemaske.
 */
class SwitcherController
{
    /**
     * Umschalten, ohne je wieder eine Anmeldemaske zu sehen.
     *
     * ## Warum es diese Route gibt
     *
     * Ein Knopf, der auf die Startseite des Ziels zeigt, hat zwei Ausgaenge:
     * Wer dort eine Sitzung hat, ist drin — wer nicht, landet auf der
     * Anmeldemaske und muss „Mit AI Brain anmelden" von Hand druecken. Genau in
     * dem Moment, fuer den die Leiste gebaut ist, waere sie also nutzlos.
     *
     * Ein Knopf, der immer durch den Anmeldefluss geht, loest das — kostet aber
     * zwei Umwege ueber AI Brain, auch wenn die Sitzung laengst steht.
     *
     * Diese Route nimmt beides: Sitzung da → sofort weiter. Sitzung nicht da →
     * durch den Anmeldefluss. Der Mensch merkt den Unterschied nicht.
     *
     * ## Wohin
     *
     * Das Ziel bestimmt das PRODUKT (`login.after_login`), nicht AI Brain. Sonst
     * muesste der Hub die Startseite jedes angebundenen Systems kennen und bei
     * jeder Umbenennung nachgezogen werden.
     */
    public function go(Request $request): RedirectResponse
    {
        $ziel = $this->ziel($request);

        if (Auth::guard((string) config('ai-brain-bridge.login.guard', 'web'))->check()) {
            return redirect()->to($ziel);
        }

        // Nicht angemeldet: durch den gewohnten Anmeldefluss, mit dem Ziel im
        // Gepaeck. Kein zweiter Fluss, damit es nur eine Stelle gibt, an der
        // etwas schiefgehen kann.
        return redirect()->route('ai-brain-bridge.login.redirect', ['ziel' => $ziel]);
    }

    /**
     * Nur produkteigene Pfade — nie ein von aussen gereichtes Ziel. Sonst waere
     * der Umschaltknopf eine Weiterleitungs-Rampe auf fremde Adressen.
     */
    protected function ziel(Request $request): string
    {
        $ziel = $request->query('ziel');

        if (is_string($ziel) && str_starts_with($ziel, '/') && ! str_starts_with($ziel, '//')) {
            return $ziel;
        }

        return (string) config('ai-brain-bridge.login.after_login', '/dashboard');
    }

    /**
     * Die Systeme der angemeldeten Person — aus dem Verzeichnis in AI Brain.
     *
     * WER gefragt wird, entscheidet nicht dieser Aufruf: Die handelnde Person
     * geht ueber den Identitaetsvertrag mit (signierte Behauptung), und AI Brain
     * loest sie dort auf. Ein Produkt kann damit nicht nach der Leiste eines
     * Fremden fragen.
     *
     * Kurz zwischengespeichert, je Person: Die Liste aendert sich im Monat
     * vielleicht einmal, die Seite laedt aber staendig.
     *
     * Durchgehend fail-safe — die Leiste ist Beiwerk und darf nie der Grund
     * sein, dass eine Seite nicht laedt. Faellt AI Brain aus, kommt eine leere
     * Liste, und im Produkt erscheint gar keine Leiste.
     */
    public function apps(Request $request): JsonResponse
    {
        $user = Auth::guard((string) config('ai-brain-bridge.login.guard', 'web'))->user();

        if ($user === null) {
            return response()->json(['apps' => []]);
        }

        $schluessel = 'ai-brain-switcher:'.$user->getAuthIdentifier();
        $dauer = (int) config('ai-brain-bridge.switcher.cache_seconds', 300);

        $apps = Cache::remember($schluessel, $dauer, fn (): array => $this->holen());

        return response()->json(['apps' => $apps]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function holen(): array
    {
        $basis = rtrim((string) config('ai-brain-bridge.base_url'), '/');

        try {
            $antwort = Http::withToken(app(OAuthTokenProvider::class)->token())
                ->withHeaders(AiBrain::actingUserHeaders())
                ->acceptJson()
                ->timeout((int) config('ai-brain-bridge.switcher.timeout', 8))
                ->get($basis.'/api/v1/users/me/apps', [
                    'aktuell' => BridgeConfig::load()['source']
                        ?? config('ai-brain-bridge.source'),
                ]);

            if (! $antwort->successful()) {
                Log::warning('Umschaltleiste: AI Brain antwortete nicht mit Erfolg', [
                    'status' => $antwort->status(),
                ]);

                return [];
            }

            $apps = $antwort->json('apps');

            return is_array($apps) ? array_values($apps) : [];
        } catch (\Throwable $e) {
            Log::warning('Umschaltleiste: AI Brain nicht erreichbar', ['fehler' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Was `@aiBrainSwitcher` ins Layout schreibt.
     *
     * Ohne Anmeldung gar nichts: Es gibt dann nichts zu wechseln, und die
     * Leiste soll auf einer Anmeldemaske nicht erscheinen.
     */
    public function markup(): string
    {
        if (! Auth::guard((string) config('ai-brain-bridge.login.guard', 'web'))->check()) {
            return '';
        }

        $script = e(route('ai-brain-bridge.switcher.script'));
        $apps = e(route('ai-brain-bridge.switcher.apps'));
        $slug = e((string) (BridgeConfig::load()['source']
            ?? config('ai-brain-bridge.source')));

        return <<<HTML
            <script src="{$script}" defer></script>
            <peppermint-app-switcher endpoint="{$apps}" aktuell="{$slug}"></peppermint-app-switcher>
            HTML;
    }

    /**
     * Die Leiste selbst — dieselbe Datei, die auch in AI Brain haengt.
     *
     * Ueber eine Route statt ueber `vendor:publish`: Eine veroeffentlichte Kopie
     * im Produkt veraltet in dem Moment, in dem das Paket sich aendert, und
     * niemand merkt es. So traegt jedes Produkt automatisch die Fassung, die
     * seine Paketversion mitbringt.
     */
    public function script(): Response
    {
        $datei = __DIR__.'/../../../resources/js/app-switcher.js';

        return response()->file($datei, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
