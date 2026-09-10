<?php

namespace Peppermint\AiBrainBridge\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
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
            return response()->json(['apps' => [], 'pins' => []]);
        }

        $dauer = (int) config('ai-brain-bridge.switcher.cache_seconds', 300);

        $nutzlast = Cache::remember($this->schluessel($user), $dauer, fn (): array => $this->holen());

        return response()->json($nutzlast);
    }

    /**
     * Anpinnen und Entfernen — durchgereicht an AI Brain (#5320).
     *
     * Das Produkt entscheidet NICHTS davon selbst: Ob eine Adresse angepinnt
     * werden darf, haengt vom Verzeichnis ab, und das kennt nur der Hub. Hier
     * wird die handelnde Person angehaengt und weitergegeben.
     *
     * Danach den Zwischenspeicher vergessen, sonst zeigte die Leiste bis zu
     * fuenf Minuten lang einen Stand, den die Person gerade selbst geaendert
     * hat.
     */
    public function pins(Request $request): JsonResponse
    {
        $user = Auth::guard((string) config('ai-brain-bridge.login.guard', 'web'))->user();

        if ($user === null) {
            return response()->json(['message' => 'Nicht angemeldet.'], 401);
        }

        $basis = rtrim((string) config('ai-brain-bridge.base_url'), '/');
        $methode = $request->isMethod('delete') ? 'delete' : 'post';

        try {
            $antwort = Http::withToken(app(OAuthTokenProvider::class)->token())
                ->withHeaders(AiBrain::actingUserHeaders())
                ->acceptJson()
                ->timeout((int) config('ai-brain-bridge.switcher.timeout', 8))
                ->{$methode}($basis.'/api/v1/users/me/switcher-pins', $request->all());
        } catch (\Throwable $e) {
            Log::warning('Umschaltleiste: Merkzettel nicht uebermittelt', ['fehler' => $e->getMessage()]);

            return response()->json(['message' => 'AI Brain ist gerade nicht erreichbar.'], 503);
        }

        Cache::forget($this->schluessel($user));

        return response()->json($antwort->json() ?? [], $antwort->status());
    }

    /**
     * Ein Zwischenspeicher-Schluessel je Person — an EINER Stelle, weil ihn
     * zwei Wege lesen: der Endpunkt (der auch fuellt) und das Layout (das nur
     * liest).
     */
    protected function schluessel(Authenticatable $user): string
    {
        return 'ai-brain-switcher:'.$user->getAuthIdentifier();
    }

    /**
     * @return array{apps: list<array<string, mixed>>, pins: list<array<string, mixed>>}
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

                return ['apps' => [], 'pins' => []];
            }

            return [
                'apps' => is_array($antwort->json('apps')) ? array_values($antwort->json('apps')) : [],
                'pins' => is_array($antwort->json('pins')) ? array_values($antwort->json('pins')) : [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Umschaltleiste: AI Brain nicht erreichbar', ['fehler' => $e->getMessage()]);

            return ['apps' => [], 'pins' => []];
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
        // Abgeschaltet: nichts. Die Direktive ist trotzdem registriert, weil
        // Blade eine unbekannte Direktive woertlich auf die Seite schreibt.
        if (! config('ai-brain-bridge.switcher.enabled') || ! config('ai-brain-bridge.login.enabled')) {
            return '';
        }

        $user = Auth::guard((string) config('ai-brain-bridge.login.guard', 'web'))->user();

        if ($user === null) {
            return '';
        }

        // Marke, die sich mit der Datei aendert — sonst bleibt eine Stunde
        // lang (`max-age`) die alte Fassung im Browser.
        $script = e(route('ai-brain-bridge.switcher.script').'?v='.$this->fassung());
        $endpunkt = e(route('ai-brain-bridge.switcher.apps'));
        $pins = e(route('ai-brain-bridge.switcher.pins'));
        $slug = e((string) (BridgeConfig::load()['source']
            ?? config('ai-brain-bridge.source')));

        // Die Liste mitgeben, WENN sie schon dasteht — aber niemals dafuer
        // losziehen.
        //
        // Hier laeuft das Rendern einer Seite. Ein Aufruf zu AI Brain an dieser
        // Stelle macht JEDE Seite dieses Produkts davon abhaengig, dass der Hub
        // schnell antwortet — und beim ersten Aufruf nach Ablauf des
        // Zwischenspeichers wartet ein Mensch darauf, obwohl er die Leiste
        // vielleicht gar nicht benutzt. Genau das war der Fehler, den ein
        // Nutzer als „jetzt dauert es laenger" gemeldet hat.
        //
        // Steht nichts bereit, faellt das Attribut weg und die Leiste holt die
        // Liste nach dem Laden ueber `endpoint`. Das kostet ein Nachpoppen —
        // aber nur beim ersten Mal, und es haelt die Seite frei.
        $bereit = Cache::get($this->schluessel($user));
        $liste = is_array($bereit) ? ' apps="'.e((string) json_encode($bereit)).'"' : '';

        return <<<HTML
            <script src="{$script}" defer></script>
            <peppermint-app-switcher endpoint="{$endpunkt}" aktuell="{$slug}" pin-endpoint="{$pins}"{$liste}></peppermint-app-switcher>
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
    protected function datei(): string
    {
        return __DIR__.'/../../../resources/js/app-switcher.js';
    }

    /**
     * Eine Marke, die sich mit der Datei aendert.
     *
     * Ohne sie bleibt eine Stunde lang (`max-age`) die alte Fassung im Browser
     * — jede Aenderung waere so lange unsichtbar, und zwar ausgerechnet fuer
     * die Leute, die gerade zugesehen haben.
     */
    protected function fassung(): string
    {
        $datei = $this->datei();

        return is_file($datei) ? (string) filemtime($datei) : 'fehlt';
    }

    public function script(): Response
    {
        $datei = $this->datei();

        return response()->file($datei, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
