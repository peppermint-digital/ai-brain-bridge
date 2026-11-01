<?php

namespace Peppermint\AiBrainBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Mcp\KanalWerkzeugGrant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nur die Werkzeuge, die der Channel wirklich haben soll (AI Brain Bug #1226,
 * Aufgabe #9278).
 *
 * ## Warum die Entscheidung HIER fallen muss
 *
 * Die per-Channel-Werkzeugauswahl lag in NanoClaw und wirkte dort als
 * `allowedTools` des Claude-SDK — das gibt nur ohne Rueckfrage frei und
 * verbietet nichts (AI-Brain-Bug #588). Am 01.11.2026 hat ein Agent in der
 * Produktion damit geschrieben: `assign-kontobewegung-tool`, nicht in seinen 24
 * Werkzeugen.
 *
 * Der Riegel dort (`disallowedTools`) schliesst den Weg ueber den MCP-Klienten.
 * Den Weg daneben schliesst er nicht: Der Acting-Nachweis steht in der
 * Prozess-Umgebung des Containers, und der Agent hat eine Shell — er koennte
 * diesen Endpunkt unmittelbar aufrufen (AI-Brain-Bug #1229).
 *
 * > **Eine Werkzeugliste im Aufrufer ist ein Zuschnitt. Eine Werkzeugliste im
 * > Zielsystem ist eine Schranke.**
 *
 * ## Was hier NICHT passiert
 *
 * Es wird keine Channel-Verwaltung nachgebaut. Die Liste kommt signiert von AI
 * Brain ({@see KanalWerkzeugGrant}) — genau so, wie
 * {@see ResolveAiBrainActingUser::CHANNEL_HEADER} es verlangt: *„Diese
 * Entscheidung gehoert nach AI Brain, wo die Channels ohnehin verwaltet
 * werden."* Hier wird eine Unterschrift geprueft, nicht entschieden.
 *
 * ## Der Melde-Modus ist der STARTZUSTAND, nicht ein Versehen
 *
 * Festlegung des Keepers vom 01.11.2026: Die Bridge startet in `report` — sie
 * prueft und protokolliert, **weist aber nichts ab**. Erst wenn ein Produkt
 * eine Woche ohne unerwartetes „waere abgewiesen" laeuft, wird es einzeln
 * scharfgestellt.
 *
 * Das ist derselbe Ablauf wie bei Bug #588 auf der AI-Brain-Seite, und der
 * Grund ist derselbe: Eine Rechte-Verschaerfung, die still passiert, legt
 * irgendeinen Channel mitten im Betrieb lahm — und zwar den, dessen Liste nie
 * jemand gegen die Wirklichkeit gehalten hat.
 *
 * ## Ohne Grant aendert sich NICHTS
 *
 * Kein Grant mitgeschickt → dieser Querschnitt tut nichts, in beiden Modi. Das
 * ist Absicht und hat einen eigenen Test: Jeder Aufrufer, der den Grant nicht
 * kennt (die 18 Produkte im Dienst-Modus, jede Oberflaeche, jeder Cron, der
 * Komm-Layer eines Menschen), laeuft bitgenau wie bisher.
 *
 * Beurteilt wird ohne Grant nur, wer sich auf einen KANAL beruft — und das hat
 * der Melde-Modus am 01.11.2026 selbst erzwungen: Die erste Fassung hielt jeden
 * Acting-User ohne Grant fuer einen Befund und haette scharf den Komm-Layer
 * abgewiesen. Zehn Eintraege in wenigen Minuten, alle legitim. Siehe
 * {@see self::handle()}.
 */
class BeschraenkeKanalWerkzeuge
{
    /** Die signierte Werkzeugliste dieses Laufs. */
    public const GRANT_HEADER = 'X-AI-Brain-Channel-Grant';

    /** Der geprueften Grant liegt danach hier fuer die Anwendung. */
    public const GRANT_ATTRIBUTE = 'ai_brain_kanal_grant';

    public const MODUS_AUS = 'off';

    public const MODUS_MELDEN = 'report';

    public const MODUS_SCHARF = 'enforce';

    public function handle(Request $request, Closure $next): Response
    {
        $modus = (string) config('ai-brain-bridge.kanal_werkzeuge.modus', self::MODUS_MELDEN);

        if ($modus === self::MODUS_AUS) {
            return $next($request);
        }

        $roh = $request->header(self::GRANT_HEADER);
        $secret = (string) (AiBrain::actingSecret() ?? '');

        $grund = [];
        $grant = KanalWerkzeugGrant::pruefen(is_string($roh) ? $roh : null, $secret, $grund);

        if ($grant !== null) {
            $request->attributes->set(self::GRANT_ATTRIBUTE, $grant);
        }

        $werkzeug = $this->gerufenesWerkzeug($request);

        /*
         * Nur `tools/call` wird beurteilt. `tools/list`, `initialize` und alles
         * uebrige geht durch — eine Liste zu lesen ist kein Vorgang, und ein
         * Riegel am Verbindungsaufbau wuerde den Channel gar nicht erst
         * hochkommen lassen. Die gelesene Liste zu BESCHNEIDEN waere schoener,
         * ist aber Ergonomie: Was zaehlt, ist der Aufruf.
         */
        if ($werkzeug === null) {
            return $next($request);
        }

        /*
         * Ohne Grant beurteilt dieser Riegel nur Aufrufe, die sich auf einen
         * KANAL berufen.
         *
         * ## Warum, und was die Messung dazu gesagt hat
         *
         * Die erste Fassung behandelte jeden Acting-User ohne Grant als Befund.
         * Der Melde-Modus hat das am 01.11.2026 binnen Minuten widerlegt: zehn
         * Eintraege, alle von legitimen Aufrufen — der Faehigkeiten-Lauf und
         * der Komm-Layer, als Mensch, auf LESENDE Werkzeuge
         * (`list-calendar-events-tool`, `list-notifications-tool`, …). Scharf
         * gestellt haette diese Fassung den Komm-Layer abgewiesen.
         *
         * Richtig ist deshalb: Ein Aufruf, der keinen Kanal behauptet, ist kein
         * Kanal-Aufruf. Was ein Mensch ueber den Komm-Layer darf, entscheidet
         * dort die Faehigkeitsliste und hier die Policy — nicht dieser Riegel.
         *
         * ## Die Grenze, die dazugehoert
         *
         * Wer den Kanal WEGLAESST und keinen Grant mitschickt, wird hier nicht
         * beurteilt. Fuer den Weg, um den es geht, ist das kein Loch: Der
         * MCP-Klient eines Agenten schickt beides, weil der Aufrufer es so baut.
         * Ein Agent, der den Weg daneben nimmt (eigener Aufruf mit dem
         * Nachweis aus seiner Umgebung) kann den Kanal auslassen — das bleibt
         * AI-Brain-Bug #1229, und es schliesst sich erst, wenn JEDER
         * Acting-User-Aufruf einen Grant traegt.
         */
        $actingUser = trim((string) $request->header(ResolveAiBrainActingUser::ACTING_USER_HEADER, ''));
        $behaupteterKanal = trim((string) $request->header(ResolveAiBrainActingUser::CHANNEL_HEADER, ''));

        /*
         * Ein VORGELEGTER, aber ungueltiger Grant ist immer ein Befund —
         * manipuliert, abgelaufen, mit fremdem Secht signiert. Sonst entkaeme
         * eine Faelschung, indem sie die Kanal-Kopfzeile weglaesst.
         */
        $vorgelegt = ($grund['vorgelegt'] ?? 'nein') === 'ja';

        if ($grant === null && ! $vorgelegt && ($actingUser === '' || $behaupteterKanal === '')) {
            return $next($request);
        }

        if ($grant === null) {
            return $this->ohneGrant($request, $next, $modus, $werkzeug, $actingUser, $behaupteterKanal, $grund);
        }

        if (! $grant->giltFuerKanal($request->header(ResolveAiBrainActingUser::CHANNEL_HEADER))) {
            return $this->abweisen(
                $request,
                $next,
                $modus,
                'Grant gilt fuer einen anderen Kanal als die Kopfzeile nennt',
                [
                    'werkzeug' => $werkzeug,
                    'grant_kanal' => $grant->kanal,
                    'kopfzeile_kanal' => $request->header(ResolveAiBrainActingUser::CHANNEL_HEADER),
                ],
            );
        }

        if (! $grant->erlaubt($werkzeug)) {
            return $this->abweisen(
                $request,
                $next,
                $modus,
                'Werkzeug steht nicht in der Werkzeugliste dieses Kanals',
                [
                    'werkzeug' => $werkzeug,
                    'kanal' => $grant->kanal,
                    'erlaubt_anzahl' => count($grant->werkzeuge),
                ],
            );
        }

        return $next($request);
    }

    /**
     * Ein Acting-User ohne Grant.
     *
     * Im Melde-Modus ist das die interessanteste Zeile des Protokolls: Sie
     * sagt, dass die Strecke den Grant noch nicht mitschickt — und solange das
     * so ist, darf `enforce` nicht gesetzt werden.
     */
    private function ohneGrant(
        Request $request,
        Closure $next,
        string $modus,
        string $werkzeug,
        string $actingUser,
        string $behaupteterKanal,
        array $grund,
    ): Response {
        return $this->abweisen($request, $next, $modus, 'Kanal-Aufruf ohne gueltigen Grant', [
            'werkzeug' => $werkzeug,
            'acting_user' => $actingUser,
            'kanal' => $behaupteterKanal,
            'grant' => $grund['grund'] ?? 'unbekannt',
        ]);
    }

    /**
     * Melden — und im scharfen Modus auch abweisen.
     *
     * @param  array<string, mixed>  $zusatz
     */
    private function abweisen(
        Request $request,
        Closure $next,
        string $modus,
        string $grund,
        array $zusatz,
    ): Response {
        $scharf = $modus === self::MODUS_SCHARF;

        Log::warning(
            $scharf
                ? 'Kanal-Werkzeugriegel: abgewiesen'
                : 'Kanal-Werkzeugriegel: waere abgewiesen (Melde-Modus)',
            ['grund' => $grund] + $zusatz,
        );

        if (! $scharf) {
            return $next($request);
        }

        /*
         * JSON-RPC-Fehler und kein HTTP-Fehler: Der Aufrufer ist ein
         * MCP-Klient, und ein 403 ohne Rumpf laesst ihn die Verbindung
         * verwerfen statt den einzelnen Aufruf. Die Meldung nennt den Grund,
         * damit ein Agent nicht dasselbe dreimal versucht.
         */
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $request->input('id'),
            'error' => [
                'code' => -32600,
                'message' => '🔒 Nicht ausgefuehrt: '.$grund.'. Dieses Werkzeug ist fuer deinen '
                    .'Kanal nicht freigegeben. Sag das dem Nutzer, statt es auf einem anderen Weg '
                    .'zu versuchen.',
            ],
        ], 200);
    }

    /**
     * Welches Werkzeug ruft dieser Aufruf? `null` bei allem, was kein
     * `tools/call` ist.
     *
     * Gelesen wird aus dem JSON-RPC-Rumpf und nicht aus dem Pfad: Der
     * MCP-Server hat EINEN Endpunkt, der Vorgang steht im Rumpf.
     */
    private function gerufenesWerkzeug(Request $request): ?string
    {
        if ((string) $request->input('method') !== 'tools/call') {
            return null;
        }

        $name = $request->input('params.name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
