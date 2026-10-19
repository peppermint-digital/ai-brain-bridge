<?php

namespace Peppermint\AiBrainBridge\Verbund;

use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Ist die Bruecke hier EINGERICHTET?
 *
 * ## Nicht „installiert" — eingerichtet
 *
 * Seit die Pakete `peppermint/ai-brain-bridge` fest verlangen, ist die
 * Frage „ist das Paket da" beantwortet. Die offene Frage ist eine andere:
 * AI Brain selbst hat das Paket (es braucht die Umschaltleiste daraus),
 * ohne es einzurichten — das Brain ist der Hub und spricht nicht mit sich
 * selbst.
 *
 * Ein `class_exists`-Test sagt dort „ja", und der erste Aufruf wirft
 * `BindingResolutionException: Unresolvable dependency … array $config`.
 * Bei den fragenden Produkten kam das als „MCP tools/call error:
 * Something went wrong" an — ein Fehlertext, der nichts verraet. Das war
 * am 18.10.2026 ein halber Tag Suche.
 *
 * Deshalb wird nicht nach der Klasse gefragt, sondern danach, ob sie sich
 * aufloesen laesst.
 *
 * ## Das ist KEIN Standalone-Schalter
 *
 * Ein Produkt ohne eingerichtete Bruecke faellt auf seine eigenen Daten
 * zurueck — das ist Betriebssicherheit fuer den Fall, dass jemand die
 * Einrichtung vergisst oder zurueckbaut, nicht die Zusicherung, dass die
 * Pakete ohne AI Brain einen Sinn ergaeben. Sie tun es nicht.
 */
class Bruecke
{
    /** @deprecated Die Fassade kann direkt benutzt werden, die Bruecke ist Pflicht. */
    public const FACADE = AiBrain::class;

    private static ?bool $gemerkt = null;

    /**
     * Steht eine eingerichtete Bruecke bereit?
     *
     * Die Antwort wird gemerkt: Sie aendert sich innerhalb eines Aufrufs
     * nicht, und die Pruefung kostet einen Container-Zugriff.
     */
    public static function eingerichtet(): bool
    {
        if (self::$gemerkt !== null) {
            return self::$gemerkt;
        }

        try {
            app(AiBrainManager::class);

            return self::$gemerkt = true;
        } catch (\Throwable) {
            // Installiert, aber nicht eingerichtet — das ist der Hub.
            return self::$gemerkt = false;
        }
    }

    /** Nur fuer Tests: die gemerkte Antwort verwerfen. */
    public static function vergessen(): void
    {
        self::$gemerkt = null;
    }
}
