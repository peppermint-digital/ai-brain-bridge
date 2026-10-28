<?php

namespace Peppermint\AiBrainBridge\Auth;

/**
 * Wo Konten, Rollen und Zuständigkeiten verwaltet werden (AI Brain #9007).
 *
 * Bastian am 28.10.2026: *„Wenn das AI-Brain aktiviert ist, muss die
 * Nutzerverwaltung im jeweiligen System ja deaktiviert sein."* Dieser Schalter
 * ist die eine Stelle, die das für alle Produkte gleich beantwortet — damit
 * nicht drei Produkte dieselbe Frage dreimal anders beantworten.
 *
 * ## Zwei Betriebsarten, nie beide zugleich
 *
 * Es ist derselbe Gedanke wie im Wiki „Zwei Betriebsarten, nie beide zugleich":
 * Mit AI Brain läuft es über AI Brain, ohne trägt sich das Produkt selbst. Die
 * verlockende dritte Variante — beide Masken offen — ist keine dritte
 * Betriebsart, sondern zwei Wahrheiten über dieselben Rechte. Und bei Rechten
 * ist das teurer als bei Stammdaten: Wer die Zuweisung lokal ändert, hat sie
 * nicht geändert, sobald AI Brain das nächste Mal schreibt.
 *
 * ## Die Vorgabe ergibt sich aus der Anbindung, nicht aus der .env
 *
 * Hier stand im ersten Entwurf `lokal` als Vorgabe, mit der Begründung, ein
 * Riegel dürfe sich beim Einspielen nicht von selbst schliessen. Für den
 * ANMELDEWEG gilt das (siehe {@see \Peppermint\AiBrainBridge\Http\Middleware\LokalerLoginRiegel}):
 * Ein zugesperrter Login sperrt aus. Hier sperrt nichts aus — es verschiebt nur,
 * WO man die Rechte setzt, und zwar dorthin, wo man ohnehin gerade ist.
 *
 * Eine Vorgabe `lokal` hätte verlangt, in jeder Forge-Site eine Zeile
 * nachzutragen; vergisst man sie bei einem Produkt, bleibt dort die zweite
 * Wahrheit stehen — und zwar unbemerkt, weil nichts kaputt aussieht. Deshalb:
 * **angebunden heisst zentral.** Wer es anders will, schreibt es hin.
 *
 *     AI_BRAIN_USER_ADMIN=lokal    # trotz Anbindung weiter im Produkt
 *     AI_BRAIN_USER_ADMIN=brain    # auch ohne Anbindung zugesperrt
 *     AI_BRAIN_USER_ADMIN=auto     # Vorgabe: angebunden => brain
 *
 * ## Was dieser Schalter NICHT anfasst
 *
 * - **Den Notzugang.** `AI_BRAIN_LOCAL_LOGIN=notzugang` und seine Ausnahmeliste
 *   bleiben unberührt. Wer bei einem Brain-Ausfall hereinkommt, kommt weiter
 *   herein — er kann dann nur keine Rechte vergeben, was ohne Brain ohnehin
 *   nicht ginge.
 * - **Das eigene Profil.** Name, Passwort, Erscheinungsbild, Benachrichtigungen:
 *   Das verwaltet niemand für jemanden, das ist keine Rechteverwaltung.
 * - **Mitarbeiterdaten.** Urlaub, Stundenlisten, Personalstamm, Offboarding im
 *   Manager hängen an einem Konto, sind aber keine Rechte. Sie bleiben dort,
 *   wo sie sind (Identity Mapping, Memory #2184). Die Grenze ist nicht „hängt
 *   an einem Nutzer", sondern „entscheidet, wer was darf oder sieht".
 */
class Nutzerverwaltung
{
    public const ZENTRAL = 'brain';

    public const LOKAL = 'lokal';

    public const AUTOMATISCH = 'auto';

    /**
     * Läuft die Nutzerverwaltung dieses Produkts über AI Brain?
     *
     * `true` heisst: Konten, Rollen, Mandanten und Bereiche werden hier nur
     * noch ANGEZEIGT. Jeder schreibende Weg ist zu — auch per Route, nicht nur
     * ausgeblendet. Siehe das Wiki „Ein Riegel vor der Tuer sagt nichts ueber
     * die Fenster".
     */
    public static function zentral(): bool
    {
        return match (static::modus()) {
            self::ZENTRAL => true,
            self::LOKAL => false,
            default => static::angebunden(),
        };
    }

    public static function lokal(): bool
    {
        return ! static::zentral();
    }

    public static function modus(): string
    {
        $roh = strtolower(trim((string) config('ai-brain-bridge.user_admin', self::AUTOMATISCH)));

        return in_array($roh, [self::ZENTRAL, self::LOKAL, self::AUTOMATISCH], true)
            ? $roh
            : self::AUTOMATISCH;
    }

    /**
     * Ist dieses Produkt an AI Brain angebunden?
     *
     * Gemessen an den Zugangsdaten, nicht an einem Schalter — es ist derselbe
     * No-op-Guard, den die ganze Bridge benutzt: Ohne `client_id`/`secret` geht
     * kein Aufruf hinaus, und dann wäre ein zugesperrtes Produkt eines, in dem
     * niemand mehr Rechte vergeben kann.
     */
    public static function angebunden(): bool
    {
        $oauth = (array) config('ai-brain-bridge.oauth', []);

        return trim((string) ($oauth['client_id'] ?? '')) !== ''
            && trim((string) ($oauth['client_secret'] ?? '')) !== '';
    }

    /**
     * Wohin verwiesen wird. Die Benutzerseite in AI Brain, nicht die Startseite:
     * Ein Verweis, der jemanden irgendwo ablegt, kostet ihn den zweiten Weg.
     *
     * @return array{zentral: bool, modus: string, url: string, hinweis: string}
     */
    public static function fuerOberflaeche(): array
    {
        return [
            'zentral' => static::zentral(),
            'modus' => static::modus(),
            'url' => static::url(),
            'hinweis' => static::hinweis(),
        ];
    }

    public static function url(?string $email = null): string
    {
        $basis = rtrim((string) config('ai-brain-bridge.base_url', ''), '/');

        if ($basis === '') {
            return '';
        }

        $ziel = $basis.'/users';

        return $email === null || trim($email) === ''
            ? $ziel
            : $ziel.'?suche='.urlencode(trim($email));
    }

    public static function hinweis(): string
    {
        return 'Konten, Rollen und Zuständigkeiten werden zentral in AI Brain vergeben. '
            .'Hier ist nur zu sehen, was dort gilt.';
    }

    /**
     * Die Meldung für einen abgewiesenen schreibenden Zugriff.
     *
     * Sichtbar machen, nicht nur verweigern — dieselbe Haltung wie beim
     * Anmelde-Riegel. Wer hier aufläuft, soll den Grund lesen und den richtigen
     * Weg finden, statt seine Rechte für kaputt zu halten.
     */
    public static function abweisung(): string
    {
        $url = static::url();

        return 'Die Nutzerverwaltung läuft in diesem System über AI Brain. '
            .'Konten, Rollen, Mandanten und Bereiche werden dort vergeben'
            .($url === '' ? '.' : ": {$url}.");
    }
}
