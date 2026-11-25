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
use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Config\BridgeConfig;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Kontoverbindung;
use Peppermint\AiBrainBridge\Switcher\AiBrainLeiste;
use Peppermint\AiBrainBridge\Switcher\Favoriten;
use Peppermint\AiBrainBridge\Switcher\LokaleEintraege;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Umschaltleiste im Produkt (AI Brain #5281).
 *
 * Zwei Aufgaben, die zusammengehoeren:
 *
 * 1. `apps()` — welche Systeme stehen dieser Person offen? Beantwortet AI Brain
 *    aus dem Verzeichnis; das Produkt fragt nur und reicht durch. Dazu kann
 *    das Produkt eigene Eintraege beisteuern ({@see LokaleEintraege}, etwa
 *    seine Module) — die beantwortet es selbst, und sie stehen unter `lokal`.
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

        // AI Brain wird nur fuer ein verbundenes Konto gefragt (pm #10219) —
        // und die Frage steht VOR dem Abruf, nicht als Filter danach. Ohne
        // Verbindung bleiben die Eintraege und Favoriten des Produkts.
        $nutzlast = ['apps' => [], 'pins' => []];

        if ($this->verbunden($user)) {
            $dauer = (int) config('ai-brain-bridge.switcher.cache_seconds', 300);
            $nutzlast = Cache::remember($this->schluessel($user), $dauer, fn (): array => $this->holen());
        }

        // Favoriten im Produkt (pm #10219): frisch aus der eigenen Tabelle,
        // nie aus dem Zwischenspeicher und nie die aus AI Brain.
        if (Favoriten::lokal()) {
            $nutzlast['pins'] = $this->favoriten($user);
        }

        // Erst NACH dem Zwischenspeicher: Die Eintraege des Produkts gehoeren
        // nie hinein (siehe `lokal()`).
        $lokal = $this->lokal($user, $request);

        if ($lokal !== null) {
            $nutzlast['lokal'] = $lokal;
        }

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

        if (Favoriten::lokal()) {
            return $this->lokalerFavorit($request, $user);
        }

        $basis = rtrim((string) config('ai-brain-bridge.base_url'), '/');
        $methode = match (true) {
            $request->isMethod('delete') => 'delete',
            $request->isMethod('patch') => 'patch',
            default => 'post',
        };

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
     * Anlegen, Umbenennen, Entfernen eines Favoriten im Produkt (pm #10219).
     *
     * Dieselben Antworten wie AI Brain (`{pin: …}`, 422 mit `message`, 404),
     * damit die Leiste nicht unterscheiden muss, wo ihre Favoriten liegen.
     */
    protected function lokalerFavorit(Request $request, Authenticatable $user): JsonResponse
    {
        $favoriten = app(Favoriten::class);

        if ($request->isMethod('delete')) {
            $daten = $request->validate(['id' => ['required', 'integer']]);
            $favoriten->entfernen($user, (int) $daten['id']);

            return response()->json(['ok' => true]);
        }

        if ($request->isMethod('patch')) {
            $daten = $request->validate([
                'id' => ['required', 'integer'],
                'label' => ['required', 'string', 'max:255'],
                'symbol' => ['nullable', 'string', 'max:32'],
            ]);

            $pin = $favoriten->aendern($user, (int) $daten['id'], $daten['label'], $request->exists('symbol'), $daten['symbol'] ?? null);

            return $pin === null
                ? response()->json(['message' => 'Diesen Favoriten gibt es nicht.'], 404)
                : response()->json(['pin' => $pin]);
        }

        $daten = $request->validate([
            'url' => ['required', 'string', 'url', 'max:2048'],
            'label' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:32'],
        ]);

        $ergebnis = $favoriten->anlegen($user, $daten['url'], $daten['label'], $daten['symbol'] ?? null);

        return isset($ergebnis['fehler'])
            ? response()->json(['message' => $ergebnis['fehler']], 422)
            : response()->json($ergebnis);
    }

    /**
     * Die Favoriten der Person aus dem Produkt — fail-safe wie die ganze
     * Leiste: Fehlt die Tabelle (Deploy vor `migrate`), keine Favoriten statt
     * einer Seite, die nicht laedt.
     *
     * @return list<array<string, mixed>>
     */
    protected function favoriten(Authenticatable $user): array
    {
        try {
            return app(Favoriten::class)->liste($user);
        } catch (\Throwable $e) {
            Log::warning('Umschaltleiste: Favoriten nicht gelesen', ['fehler' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Ist die Person mit AI Brain verbunden? Davon haengt ab, ob die Leiste
     * AI Brain ueberhaupt fragt (pm #10219) — {@see Kontoverbindung}.
     */
    protected function verbunden(Authenticatable $user): bool
    {
        return app(Kontoverbindung::class)->istVerbunden($user);
    }

    /**
     * Die Eintraege, die das Produkt der Leiste beisteuert — oder `null`, wenn
     * es keine beisteuert.
     *
     * `null` und `[]` sind verschieden: `null` heisst „Vertrag nicht
     * gebunden", und dann bleibt alles byte-gleich wie vor dem Vertrag (kein
     * Schluessel `lokal`). `[]` heisst „gebunden, aber diese Person sieht
     * nichts davon".
     *
     * Frisch bei jedem Aufruf und NIE im Zwischenspeicher: Die Antwort von AI
     * Brain liegt fuenf Minuten je Person dort. Stuenden die Eintraege mit
     * darin, saehe jemand ein entzogenes Modul noch fuenf Minuten lang — und
     * landete beim Klick auf einer 403.
     *
     * Fail-safe wie die ganze Leiste: Wirft das Produkt, gibt es keine
     * Eintraege, und die Seite laedt trotzdem.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function lokal(Authenticatable $user, Request $request): ?array
    {
        if (! app()->bound(LokaleEintraege::class)) {
            return null;
        }

        try {
            return array_values(app(LokaleEintraege::class)->fuer($user, $request));
        } catch (\Throwable $e) {
            Log::warning('Umschaltleiste: Eintraege des Produkts nicht ermittelt', ['fehler' => $e->getMessage()]);

            return [];
        }
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
        // Faellt AI Brain aus, eine leere Liste — die Leiste ist Beiwerk.
        return app(AiBrainLeiste::class)->abrufen() ?? ['apps' => [], 'pins' => []];
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
        //
        // Seit pm #10190 nur noch am eigenen Schalter: Ohne Anmeldeweg zeigt
        // die Leiste die Eintraege des Produkts, ohne Systeme aus AI Brain und
        // ohne Merkzettel.
        if (! config('ai-brain-bridge.switcher.enabled')) {
            return '';
        }

        $user = Auth::guard((string) config('ai-brain-bridge.login.guard', 'web'))->user();

        if ($user === null) {
            return '';
        }

        $mitAnmeldeweg = self::mitAnmeldeweg();
        $verbunden = $this->verbunden($user);
        $lokaleFavoriten = Favoriten::lokal();

        // Marke, die sich mit der Datei aendert — sonst bleibt eine Stunde
        // lang (`max-age`) die alte Fassung im Browser.
        $script = e(route('ai-brain-bridge.switcher.script').'?v='.$this->fassung());
        $endpunkt = e(route('ai-brain-bridge.switcher.apps'));
        // Ohne `pin-endpoint` blendet die Leiste das Anpinnen selbst aus.
        $pins = ($mitAnmeldeweg || $lokaleFavoriten) ? ' pin-endpoint="'.e(route('ai-brain-bridge.switcher.pins')).'"' : '';
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
        //
        // Nur fuer ein verbundenes Konto (pm #10219): Eine bereitliegende
        // Liste ist die Antwort von AI Brain fuer diese Person.
        $bereit = $verbunden ? Cache::get($this->schluessel($user)) : null;

        // Die Eintraege des Produkts stehen immer sofort bereit — sie kosten
        // kein Netz. Fehlt die Liste von AI Brain, geht NUR `lokal` mit (ohne
        // `apps`): Die Leiste nimmt die Systeme dann aus ihrem eigenen
        // Speicher und fragt danach wie bisher nach.
        $lokal = $this->lokal($user, request());

        // Ohne Verbindung gibt es nur die eigenen Eintraege. Hat das Produkt
        // keine (oder sieht diese Person keinen), gaebe es eine leere Leiste.
        if (! $verbunden && ($lokal === null || $lokal === [])) {
            return '';
        }

        // Ohne Verbindung AUSDRUECKLICH keine Systeme: Fehlt `apps`, nimmt
        // die Leiste sie aus dem Sitzungsspeicher des Browsers — womoeglich
        // eine Liste aus der Zeit vor dem Trennen, mit AI Brain darin.
        if (! $verbunden) {
            $bereit = ['apps' => []];
        }

        // Die Favoriten des Produkts kosten kein Netz — sie stehen immer bereit.
        if ($lokaleFavoriten) {
            $bereit = [...(is_array($bereit) ? $bereit : []), 'pins' => $this->favoriten($user)];
        }

        if ($lokal !== null) {
            $bereit = [...(is_array($bereit) ? $bereit : []), 'lokal' => $lokal];
        }

        $liste = is_array($bereit) ? ' apps="'.e((string) json_encode($bereit)).'"' : '';

        $postfach = $this->postfach();

        // Der Name der Anwendung, damit die Leiste ihn aus Seitentiteln
        // kuerzen kann, auch ohne Liste aus AI Brain (pm #10219).
        $anwendung = e((string) config('app.name'));

        return <<<HTML
            <script src="{$script}" defer></script>
            <peppermint-app-switcher endpoint="{$endpunkt}" aktuell="{$slug}" anwendung="{$anwendung}"{$pins}{$postfach}{$liste}></peppermint-app-switcher>
            HTML;
    }

    /**
     * Die Attribute fuer die Postfach-Kachel — oder nichts.
     *
     * ## Warum die Leiste und nicht die Anwendung
     *
     * Die Glocke sass bisher in jedem Produkt und meinte dort nur dessen
     * eigene Meldungen. Seit AI Brain spiegelt, steht in jedem System alles;
     * vier Glocken mit demselben Inhalt sind drei zu viel. Der Posteingang
     * gehoert deshalb dorthin, wo man ohnehin zwischen den Systemen wechselt.
     *
     * ## Warum ueber Routen-Namen und nicht ueber feste Pfade
     *
     * Ein Produkt ohne `peppermint/notifications` hat keine
     * Posteingangs-Seite. Ein Symbol, das dort auf einen 404 fuehrt, ist
     * schlimmer als keines — also erscheint es nur, wenn beide Routen
     * wirklich registriert sind.
     *
     * Welche Routen das sind, entscheiden {@see postfachSeite()} und
     * {@see postfachZaehler()}: der gemeinsame Posteingang, wenn es ihn
     * gibt, sonst die Meldungsseite (AI Brain #1152).
     */
    protected function postfach(): string
    {
        $seite = self::postfachSeite();

        if ($seite === null || ! self::zeigtPostfach()) {
            return '';
        }

        return ' postfach="'.e(route($seite)).'"'
            .' postfach-zaehler="'.e(route(self::postfachZaehler())).'"';
    }

    /**
     * Welche Seite hinter der Kachel liegt (AI Brain #1152).
     *
     * ## Was hier bis zum 29.10.2026 stand
     *
     * Fest `notifications.inbox` — die produkteigene Meldungsseite. Das war
     * richtig, als es die gemeinsame Seite noch nicht gab.
     *
     * Seit es sie gibt, zeigte die Kachel auf einen Ausschnitt, waehrend das
     * Posteingangs-Symbol in der Kopfzeile desselben Produkts auf den
     * gemeinsamen Posteingang fuehrte. Zwei Briefkaesten mit verschiedenem
     * Inhalt, in derselben Kopfzeile. Bastian dazu: „da ist der Posteingang
     * irgendwie noch ein anderer … Der Richtige ist der, der jetzt in den
     * Einzelsystemen angezeigt wird."
     *
     * ## Die Reihenfolge ist die Aussage
     *
     * Der gemeinsame Posteingang gewinnt. Die Meldungsseite bleibt als
     * RUECKFALL — nicht aus Nachsicht, sondern weil ein Produkt ohne
     * `peppermint/inbox` keine gemeinsame Seite hat, und ein Symbol auf
     * einen 404 schlimmer ist als keines.
     *
     * Dieser Rueckfall traegt ausserdem das Ausrollfenster: Zwischen dem
     * Ziehen dieses Pakets und dem Ziehen des Inbox-Pakets liegt in jedem
     * Produkt eine Luecke, und darin soll die Kachel stehenbleiben und
     * nicht verschwinden.
     */
    protected static function postfachSeite(): ?string
    {
        foreach (['posteingang', 'notifications.inbox'] as $name) {
            if (Route::has($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Woher die Zahl an der Kachel kommt (AI Brain #1152).
     *
     * `inbox.ungelesen` aus `peppermint/inbox` nennt den GLOBALEN Bestand —
     * alle Arten, alle Systeme, also das, was hinter der Kachel steht.
     *
     * `inbox.api.unread-count` ist der Rueckfall und zaehlt nur die
     * ungelesenen Meldungen DIESES Systems. Er stand hier allein, und damit
     * sprach die Zahl ueber etwas anderes als die Seite, die sie aufmacht.
     * Er bleibt, weil er in der Luecke beim Ausrollen die einzige Zahl ist,
     * die es gibt — eine Kachel ohne Zahl saehe aus wie „nichts Neues".
     */
    protected static function postfachZaehler(): string
    {
        foreach (['inbox.ungelesen', 'inbox.api.unread-count'] as $name) {
            if (Route::has($name)) {
                return $name;
            }
        }

        // Unerreichbar, solange `zeigtPostfach()` beide Namen prueft. Steht
        // hier, damit `route(null)` nicht erst in Produktion auffaellt.
        return 'inbox.api.unread-count';
    }

    /**
     * Haengt der Posteingang dieses Produkts in der Leiste?
     *
     * Die Frage stellt sich an zwei Stellen, und sie muss an beiden gleich
     * beantwortet werden: hier, beim Zeichnen der Kachel — und im Produkt,
     * das seine eigene Glocke genau dann weglaesst.
     *
     * Liefen die beiden auseinander, waere der schlimme Fall nicht die
     * doppelte Anzeige, sondern die gar keine: keine Kachel, keine Glocke,
     * und der Posteingang nur noch ueber die Adresszeile erreichbar.
     */
    /**
     * Gibt es den gemeinsamen Anmeldeweg? Daran haengen die Systeme aus AI
     * Brain, der Merkzettel und die Postfach-Kachel — nicht die Leiste selbst
     * (pm #10190).
     */
    public static function mitAnmeldeweg(): bool
    {
        return (bool) config('ai-brain-bridge.login.enabled');
    }

    public static function zeigtPostfach(): bool
    {
        return (bool) config('ai-brain-bridge.switcher.enabled')
            && (bool) config('ai-brain-bridge.login.enabled')
            && self::postfachSeite() !== null
            && (Route::has('inbox.ungelesen') || Route::has('inbox.api.unread-count'));
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
