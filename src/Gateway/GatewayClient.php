<?php

namespace Peppermint\AiBrainBridge\Gateway;

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;

/**
 * Einen Vorgang bei einem ANDEREN System auslösen — über AI Brain (#5243).
 *
 * ## Was das ersetzt
 *
 * Die Peer-Schiene: bisher redete jedes Produkt direkt mit jedem anderen, jede
 * Verbindung mit eigenem Geheimnis, eigener Rechteprüfung und eigenem (oder
 * keinem) Protokoll. Wer eine Gegenstelle dazunahm, baute die Verbindung neu.
 *
 * ## Der Unterschied im Aufruf
 *
 * Man nennt einen VORGANG, kein Zielsystem:
 *
 *     AiBrain::gateway('tasks.create', ['title' => 'Ticket 5']);
 *
 * Welches System das bedient, weiß das Fähigkeits-Register in Brain — gemessen
 * an dem, was die Systeme tatsächlich anbieten. Die Verwaltung muss also nicht
 * wissen, wo Aufgaben liegen; sie muss nur wissen, dass sie welche braucht.
 * Zieht der Vorgang eines Tages in ein anderes System um, ändert sich hier
 * nichts.
 *
 * ## Fehler sind unterscheidbar
 *
 * `not_offered` (das kann gerade niemand), `not_allowed` (dieses System darf
 * das nicht), `unreachable` (das Ziel antwortet nicht), `tool_failed` (das Ziel
 * hat abgelehnt). Ein Aufrufer soll wissen, ob er es später noch einmal
 * versuchen soll — ohne Zeichenketten zu raten.
 */
class GatewayClient
{
    /**
     * @param  array<string, mixed>  $config
     * @param  callable(): array<string, string>  $actingHeaders
     */
    public function __construct(
        protected array $config,
        protected OAuthTokenProvider $tokens,
        protected $actingHeaders,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  string|null  $product  Nur angeben, wenn ausdrücklich EIN System gemeint ist.
     * @return array{ok: bool, data: array<mixed>|null, text: string|null, error: string|null, message: string|null, product: string|null}
     */
    public function call(string $capability, array $arguments = [], ?string $product = null): array
    {
        $url = rtrim((string) ($this->config['base_url'] ?? ''), '/').'/api/v1/gateway';

        try {
            $antwort = Http::withToken($this->tokens->token())
                ->withHeaders(($this->actingHeaders)())
                ->acceptJson()
                ->timeout((int) ($this->config['gateway']['timeout'] ?? 20))
                ->post($url, array_filter([
                    'capability' => $capability,
                    'arguments' => $arguments,
                    'product' => $product,
                ], fn ($wert) => $wert !== null));
        } catch (\Throwable $e) {
            // Auch der Weg ZU Brain kann ausfallen. Das ist derselbe Fall wie
            // ein nicht erreichbares Zielsystem — und muss sich für den
            // Aufrufer auch so anfühlen, statt als Ausnahme hochzuschlagen.
            return $this->fehler('unreachable', 'AI Brain nicht erreichbar: '.$e->getMessage());
        }

        $daten = $antwort->json();

        if (! is_array($daten)) {
            return $this->fehler('tool_failed', 'Unverständliche Antwort von AI Brain (HTTP '.$antwort->status().')');
        }

        return [
            'ok' => (bool) ($daten['ok'] ?? false),
            'data' => is_array($daten['data'] ?? null) ? $daten['data'] : null,
            'text' => $daten['text'] ?? null,
            'error' => $daten['error'] ?? null,
            'message' => $daten['message'] ?? null,
            'product' => $daten['product'] ?? null,
        ];
    }

    /**
     * Welche Systeme bedienen einen Vorgang? (#7490)
     *
     * ## Warum das NICHT dasselbe ist wie `call()`
     *
     * `call()` nennt einen Vorgang und verschweigt das Zielsystem — genau
     * richtig fuer „lege eine Aufgabe an": Das aufrufende System soll nicht
     * wissen muessen, wo Aufgaben liegen.
     *
     * Fuer einen Kalender ist das Zielsystem keine Verlegenheit, sondern
     * Inhalt: Jedes angebundene System wird eine eigene Quelle mit eigener
     * Farbe, eigenem Filterknopf und eigenem „hier anlegen". Dafuer braucht
     * man die Liste, nicht nur den ersten Treffer.
     *
     * ## Was zurueckkommt
     *
     * Nur Systeme, fuer die DIESES System freigeschaltet ist — Brain filtert
     * nach denselben Freigaben wie beim Weiterleiten. Das eigene System steht
     * nicht darin. Ein Fehlschlag ist eine leere Liste und keine Ausnahme:
     * Wer die Anbieter nicht erfragen kann, hat eben keine fremden Quellen,
     * und der eigene Kalender soll davon nicht leer werden.
     *
     * Bewusst OHNE Zwischenspeicher. Das Register wird in Brain gemessen und
     * nicht gepflegt; ein Zwischenspeicher hier wuerde ein neu angebundenes
     * System verspaetet erscheinen und ein abgeschaltetes nachwirken lassen.
     * Wer den Netzweg sparen will, legt den Speicher dort an, wo die Quellen
     * gebaut werden — die Entscheidung gehoert der Anwendung.
     *
     * @return array<int, array{slug: string, name: string|null, key: string, label: string, url: string|null, capabilities: array<int, string>}>
     */
    public function providers(string $capability): array
    {
        $url = rtrim((string) ($this->config['base_url'] ?? ''), '/')
            .'/api/v1/capabilities/'.rawurlencode($capability).'/providers';

        try {
            $antwort = Http::withToken($this->tokens->token())
                ->withHeaders(($this->actingHeaders)())
                ->acceptJson()
                ->timeout((int) ($this->config['gateway']['timeout'] ?? 20))
                ->get($url);
        } catch (\Throwable) {
            return [];
        }

        $daten = $antwort->json();

        if (! is_array($daten) || ! ($daten['ok'] ?? false)) {
            return [];
        }

        $anbieter = [];

        foreach ((array) ($daten['providers'] ?? []) as $zeile) {
            // Ohne Slug ist eine Zeile unbrauchbar: Er ist es, mit dem der
            // naechste Aufruf adressiert wird.
            if (! is_array($zeile) || ! isset($zeile['slug'])) {
                continue;
            }

            $slug = (string) $zeile['slug'];
            $name = isset($zeile['name']) ? (string) $zeile['name'] : null;

            $anbieter[] = [
                'slug' => $slug,
                'name' => $name,
                // Der KURZE Schluessel und die kurze Beschriftung — an EINER
                // Stelle abgeleitet, nicht in jedem Produkt neu.
                //
                // Das ist keine Bequemlichkeit: Der Schluessel steckt in den
                // Termin-Kennungen („manager:7") und in den gespeicherten
                // Filtern der Nutzer. Leitete ihn jedes Produkt selbst ab,
                // hiesse dasselbe System in zwei Oberflaechen irgendwann
                // verschieden — und ein gespeicherter Filter waere stumm
                // falsch, ohne dass etwas bricht.
                //
                // Hier und nicht im Kalender-Paket, weil es Peppermint-Wissen
                // ist: Dass unsere Produkte `peppermint-` vorne tragen, geht
                // ein fremdes Kalender-Paket nichts an.
                'key' => $this->kurzerSchluessel($slug),
                'label' => $this->kurzeBeschriftung($name, $slug),
                'url' => isset($zeile['url']) ? (string) $zeile['url'] : null,
                // Was dieses System kann UND wir ausloesen duerfen. Ohne die
                // Liste muesste der Aufrufer je Vorgang erneut fragen — und
                // raten darf er nicht: Ein Knopf, der beim Druecken abgewiesen
                // wird, ist schlechter als keiner.
                'capabilities' => array_values(array_filter(
                    (array) ($zeile['capabilities'] ?? []),
                    'is_string',
                )),
            ];
        }

        return $anbieter;
    }

    /** `peppermint-verwaltung` wird zu `verwaltung`. */
    protected function kurzerSchluessel(string $slug): string
    {
        return str_starts_with($slug, 'peppermint-') ? substr($slug, strlen('peppermint-')) : $slug;
    }

    /**
     * `Peppermint Verwaltung` wird zu `Verwaltung`.
     *
     * Faellt auf den Slug zurueck, wenn kein Name kommt — eine Quelle ohne
     * Beschriftung waere ein Filterknopf ohne Aufschrift.
     */
    protected function kurzeBeschriftung(?string $name, string $slug): string
    {
        $kurz = trim(str_replace('Peppermint', '', (string) $name));

        return $kurz !== '' ? $kurz : $this->kurzerSchluessel($slug);
    }

    /**
     * @return array{ok: bool, data: null, text: null, error: string, message: string, product: null}
     */
    protected function fehler(string $klasse, string $meldung): array
    {
        return [
            'ok' => false,
            'data' => null,
            'text' => null,
            'error' => $klasse,
            'message' => $meldung,
            'product' => null,
        ];
    }
}
