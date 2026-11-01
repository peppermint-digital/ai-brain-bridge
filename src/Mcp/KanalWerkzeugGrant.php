<?php

namespace Peppermint\AiBrainBridge\Mcp;

/**
 * Welche Werkzeuge darf der Channel, der gerade ruft? (AI Brain Bug #1226,
 * Aufgabe #9278)
 *
 * ## Warum es diesen Grant gibt
 *
 * Die per-Channel-Werkzeugauswahl lag bis zum 01.11.2026 ausschliesslich in
 * NanoClaw und wirkte dort als `allowedTools` des Claude-SDK — **das gibt nur
 * ohne Rueckfrage frei und verbietet nichts** (AI-Brain-Bug #588). Gemessen am
 * 01.11.2026 in der Produktion: Ein Buchhaltungs-Agent rief ein Werkzeug, das
 * nicht in seinen 24 stand, und schrieb damit.
 *
 * Der Riegel dort (`disallowedTools`) schliesst den Weg ueber den MCP-Klienten.
 * Er schliesst nicht den Weg daneben: Der Acting-Nachweis liegt in der
 * Prozess-Umgebung des Containers, und der Agent hat eine Shell — er koennte
 * diesen Endpunkt unmittelbar aufrufen (AI-Brain-Bug #1229). **Darum muss die
 * Entscheidung hier fallen, im Zielsystem.**
 *
 * ## Warum der Grant und nicht die Channel-Verwaltung im Produkt
 *
 * Weil {@see \Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser}
 * es richtig begruendet: Die Kopfzeile mit dem Channel ist bewusst **kein**
 * Autorisierungs-Input, denn *„wuerde ein Produkt daraus Rechte ableiten,
 * muesste die Channel-Verwaltung in jedem Produkt nachgebaut und gepflegt
 * werden. Diese Entscheidung gehoert nach AI Brain, wo die Channels ohnehin
 * verwaltet werden."*
 *
 * Genau das leistet der Grant: **AI Brain entscheidet und signiert, das Produkt
 * prueft nur.** Hier wird nichts verwaltet und nichts nachgebaut — hier wird
 * eine Unterschrift geprueft.
 *
 * ## Warum er nicht faelschbar und nicht alterungsfaehig ist
 *
 * - **Nicht faelschbar:** Signiert mit dem Event-Secret dieses Produkts. Der
 *   Container sieht dieses Geheimnis nie; NanoClaw laesst sich den Grant von AI
 *   Brain ausstellen und reicht ihn nur durch.
 * - **Altert nicht:** Die Liste gehoert zum LAUF und nicht zu einem
 *   Zwischenspeicher. Eine gespeicherte Liste haette das Problem, das bei
 *   Bug #588 gegen Komplement-Listen sprach — ein neues Werkzeug stuende nicht
 *   darin und waere damit erlaubt. Hier ist die Richtung umgekehrt: Was nicht
 *   im Grant steht, ist nicht erlaubt, auch wenn es neu ist.
 * - **Kurzlebig:** `ablauf` deckt die Laufdauer plus Puffer. Ein abgelaufener
 *   Grant ist kein Grant.
 *
 * ## Die Nutzlast
 *
 *     base64url({"kanal":"beleg-extraktion","werkzeuge":[…],"lauf":"…","ablauf":1762…})
 *     .sha256=<hmac ueber genau diese base64url-Zeichenkette>
 *
 * Signiert wird die **kodierte** Zeichenkette und nicht das wieder
 * zusammengesetzte JSON: Sonst haengt die Pruefung an der Schluesselreihenfolge
 * und an Kodierungsdetails, und zwei Seiten rechnen irgendwann verschiedene
 * HMACs ueber dasselbe Objekt.
 */
final class KanalWerkzeugGrant
{
    /**
     * @param  list<string>  $werkzeuge
     */
    private function __construct(
        public readonly string $kanal,
        public readonly array $werkzeuge,
        public readonly ?string $lauf,
        public readonly int $ablauf,
    ) {}

    /**
     * Einen Grant ausstellen — fuer AI Brain und fuer Tests.
     *
     * @param  list<string>  $werkzeuge
     */
    public static function ausstellen(
        string $kanal,
        array $werkzeuge,
        string $secret,
        int $gueltigSekunden = 3600,
        ?string $lauf = null,
    ): string {
        $nutzlast = self::kodieren([
            'kanal' => $kanal,
            'werkzeuge' => array_values(array_map('strval', $werkzeuge)),
            'lauf' => $lauf,
            // `now()` und nicht `time()` — dieselbe Uhr wie auf der
            // Ausstellerseite in AI Brain, und die einzige, die ein Test
            // anhalten kann.
            'ablauf' => now()->timestamp + $gueltigSekunden,
        ]);

        return $nutzlast.'.sha256='.hash_hmac('sha256', $nutzlast, $secret);
    }

    /**
     * Einen Grant pruefen. `null` heisst: kein gueltiger Grant — und zwar
     * ohne Unterscheidung nach aussen, der GRUND steht im Protokoll.
     *
     * Jeder Fehlschlag gibt `null` zurueck, auch der abgelaufene und der
     * manipulierte: Wer hier verschiedene Antworten gibt, sagt einem Aufrufer,
     * woran er noch drehen muss.
     *
     * @param  array<string, string>  $grund  Wird mit dem Grund gefuellt (fuer das Protokoll)
     */
    public static function pruefen(?string $roh, string $secret, array &$grund = []): ?self
    {
        $grund = [];

        if ($roh === null || trim($roh) === '') {
            $grund['grund'] = 'kein Grant mitgeschickt';
            $grund['vorgelegt'] = 'nein';

            return null;
        }

        /*
         * Ab hier hat jemand etwas VORGELEGT. Das ist eine eigene Auskunft und
         * kein Textvergleich auf den Grund: Ein vorgelegter, aber ungueltiger
         * Grant ist ein Befund — unabhaengig davon, was sonst in den Kopfzeilen
         * steht. Wer sonst nach dem Grund-TEXT unterscheiden muesste, haette
         * beim naechsten neuen Grund eine stille Luecke.
         */
        $grund['vorgelegt'] = 'ja';

        if ($secret === '') {
            /*
             * Ohne Secret gibt es nichts zu pruefen. Das ist dieselbe
             * fail-safe-Doktrin wie bei der Signatur des Acting-Users: Eine
             * frische Installation laeuft ohne Secret-Verteilung — sie hat dann
             * aber auch diesen Riegel nicht, und der Aufrufer muss es wissen.
             *
             * Und sie gilt NICHT als vorgelegt: Dass hier kein Geheimnis liegt,
             * ist ein Zustand dieser Installation und kein Versuch von drueben.
             */
            $grund['grund'] = 'kein Event-Secret konfiguriert';
            $grund['vorgelegt'] = 'nein';

            return null;
        }

        $teile = explode('.sha256=', trim($roh), 2);

        if (count($teile) !== 2) {
            $grund['grund'] = 'Grant hat nicht die Form <nutzlast>.sha256=<hmac>';

            return null;
        }

        [$nutzlast, $hmac] = $teile;

        if (! hash_equals(hash_hmac('sha256', $nutzlast, $secret), $hmac)) {
            $grund['grund'] = 'Signatur passt nicht';

            return null;
        }

        $daten = self::dekodieren($nutzlast);

        if ($daten === null) {
            $grund['grund'] = 'Nutzlast ist kein gueltiges JSON-Objekt';

            return null;
        }

        $kanal = trim((string) ($daten['kanal'] ?? ''));
        $werkzeuge = $daten['werkzeuge'] ?? null;
        $ablauf = $daten['ablauf'] ?? null;

        if ($kanal === '' || ! is_array($werkzeuge) || ! is_int($ablauf)) {
            $grund['grund'] = 'Nutzlast ist unvollstaendig';

            return null;
        }

        if ($ablauf <= now()->timestamp) {
            $grund['grund'] = 'Grant ist abgelaufen';

            return null;
        }

        return new self(
            $kanal,
            array_values(array_map('strval', $werkzeuge)),
            isset($daten['lauf']) ? (string) $daten['lauf'] : null,
            $ablauf,
        );
    }

    /** Deckt dieser Grant das genannte Werkzeug? */
    public function erlaubt(string $werkzeug): bool
    {
        return in_array($werkzeug, $this->werkzeuge, true);
    }

    /**
     * Gilt dieser Grant fuer den Channel, der sich in der Kopfzeile nennt?
     *
     * Die Kopfzeile ist unsigniert und damit kein Nachweis — der Grant ist
     * einer. Diese Pruefung schuetzt deshalb nicht den Grant, sondern deckt
     * einen WIDERSPRUCH auf: Nennt die unsignierte Kopfzeile einen anderen
     * Channel als der signierte Grant, stimmt an der Strecke etwas nicht, und
     * das gehoert gemeldet statt ausgelegt.
     */
    public function giltFuerKanal(?string $behaupteterKanal): bool
    {
        if ($behaupteterKanal === null || trim($behaupteterKanal) === '') {
            return true;
        }

        return trim($behaupteterKanal) === $this->kanal;
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private static function kodieren(array $daten): string
    {
        return rtrim(strtr(base64_encode(
            (string) json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function dekodieren(string $nutzlast): ?array
    {
        $roh = base64_decode(strtr($nutzlast, '-_', '+/'), true);

        if ($roh === false) {
            return null;
        }

        $daten = json_decode($roh, true);

        return is_array($daten) ? $daten : null;
    }
}
