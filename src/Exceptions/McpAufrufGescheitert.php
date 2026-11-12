<?php

namespace Peppermint\AiBrainBridge\Exceptions;

/**
 * Ein MCP-Aufruf ist gescheitert — und sagt, WORAN.
 *
 * ## Warum es diese Klasse gibt
 *
 * {@see \Peppermint\AiBrainBridge\Mcp\McpClient} warf bis zum 12.11.2026
 * ausschliesslich {@see AiBrainBridgeException}, und der HTTP-Status stand nur
 * im MELDUNGSTEXT:
 *
 *     throw new AiBrainBridgeException("MCP {$method} failed: HTTP {$response->status()}");
 *
 * Fuer einen Aufrufer, der die vier Fehlerarten des Komm-Layers
 * unterscheiden will — `not_offered`, `not_allowed`, `unreachable`,
 * `tool_failed` —, ist das keine Auskunft, sondern eine Zeichenkette. Wer
 * „HTTP 401" aus einem Text liest, prueft eine SCHREIBWEISE: Sie aendert
 * sich bei der naechsten Umformulierung, und nichts wird dabei rot.
 *
 * Die Folge war messbar und ist der Anlass (Aufgabe #9882, Bug #1417): Das
 * Kalenderpaket fing jeden `Throwable` und machte daraus ausnahmslos
 * „die Nabe ist nicht erreichbar". **Ein HTTP 401 oder 403 ist aber eine
 * ABSAGE, keine Stoerung** — die Nabe hat sehr wohl geantwortet. In der
 * Anlege-Maske des Managers stand deshalb „Nicht erreichbar: AI Brain
 * (privat)", wo „nicht freigegeben" richtig gewesen waere. Die
 * Reproduktionsschritte von #1417 sagen es selbst: „leere OAuth-Zugangsdaten
 * reichen, Aufruf endet in HTTP 401".
 *
 * ## Warum eine UNTERKLASSE und kein Feld an der Oberklasse
 *
 * Damit nichts umzuschreiben ist. Gemessen am 12.11.2026 ueber AI Brain, die
 * drei Produkte und die Quellen von acht Paketen:
 *
 * | | |
 * |---|---|
 * | faengt `AiBrainBridgeException` NAMENTLICH | **0** |
 * | erbt von ihr | **0** |
 * | konstruiert sie | **4** — alle vier INNERHALB der Bruecke |
 * | faengt eine Oberklasse (`Throwable`/`Exception`/`RuntimeException`) | 548 / 77 / 32 |
 *
 * Eine Unterklasse ist ein `AiBrainBridgeException`, also fangen diese 657
 * Stellen sie unveraendert. Die Oberklasse behaelt ihre Signatur, kein
 * bestehender `catch` wird angefasst, und wer den Status nicht braucht,
 * merkt nichts. Ein Feld an der Oberklasse haette dagegen ihren Konstruktor
 * angefasst — und den rufen die vier Stellen oben auf.
 *
 * ## Was hier bewusst NICHT passiert: die Abbildung auf die vier Arten
 *
 * Diese Klasse traegt die TATSACHE (der Status), nicht ihre Deutung. Die
 * Abbildung `401/403 -> not_allowed`, `404 -> not_offered`,
 * `503 -> unreachable`, Rest `-> tool_failed` steht beim Aufrufer.
 *
 * Das ist keine Faulheit, sondern eine offene Entscheidung: Die vier Namen
 * sind heute Zeichenketten, die die NABE in der Antwort schickt
 * ({@see \Peppermint\AiBrainBridge\Gateway\GatewayClient::call()} liest sie
 * aus `$daten['error']`); die Bruecke hat dafuer keinen eigenen Typ. Einen
 * einzufuehren waere ein neues Vokabular an einer Stelle, die vor allen vier
 * Produkten sitzt — und das gehoert entschieden, nicht nebenbei gebaut.
 *
 * **Anheftstelle fuer den Naechsten:** Sobald ein ZWEITES Paket dieselbe
 * Abbildung braucht, ist sie zweimal da und laeuft auseinander. Dann gehoert
 * sie hierher, als benannter Typ. Heute braucht sie nur
 * `peppermint/calendar` (`src/Brain/KalenderFreigabe.php`), und eine
 * Abstraktion fuer einen Fall ist eine Vermutung ueber den zweiten.
 */
class McpAufrufGescheitert extends AiBrainBridgeException
{
    /**
     * @param  int|null  $httpStatus  Der Status der abgelehnten Antwort —
     *                                oder `null`, wenn die ZUSTELLUNG klappte
     *                                (HTTP 2xx) und erst die Nutzlast einen
     *                                JSON-RPC-Fehler trug. `null` heisst also
     *                                nicht „unbekannt", sondern „die Nabe hat
     *                                geantwortet und das Werkzeug hat
     *                                abgelehnt" — die zwei Ebenen von „ok"
     *                                aus Wiki #595, hier als Typ.
     * @param  string  $methode  Die JSON-RPC-Methode (`tools/list`,
     *                           `tools/call`), damit die Meldung weiter
     *                           dasselbe sagt wie vorher.
     */
    public function __construct(
        public readonly ?int $httpStatus,
        public readonly string $methode,
        string $nachricht,
    ) {
        parent::__construct($nachricht);
    }

    /** Die Zustellung scheiterte: ein Status ausserhalb von 2xx. */
    public static function status(string $methode, int $status): self
    {
        // Der Text bleibt WORTGLEICH zu dem, der hier bis zum 12.11.2026
        // stand. Er steht in Logs und moeglicherweise in Suchmustern; ihn
        // nebenbei umzuformulieren waere eine zweite Aenderung in einer
        // Aenderung, die additiv sein soll.
        return new self($status, $methode, "MCP {$methode} failed: HTTP {$status}");
    }

    /** Zugestellt, aber die Nutzlast trug einen JSON-RPC-Fehler. */
    public static function nutzlast(string $methode, string $grund): self
    {
        return new self(null, $methode, "MCP {$methode} error: {$grund}");
    }

    /**
     * Hat die Nabe geantwortet und ABGELEHNT?
     *
     * 401 und 403 zusammen, und das ist Absicht: Fuer den Aufrufer sind sie
     * dieselbe Lage — die Nabe hat die handelnde Person oder den Zugang
     * dieses Produkts nicht akzeptiert. Ob das Geheimnis fehlt (401) oder
     * nicht reicht (403), aendert nichts daran, was eine Oberflaeche sagen
     * soll, und beides behebt dieselbe Person an derselben Stelle.
     */
    public function istAbsage(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }
}
