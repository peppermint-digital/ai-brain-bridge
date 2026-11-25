<?php

namespace Peppermint\AiBrainBridge\Verbund;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Ist das eigene Konto mit dem anderen System verbunden? (pm #10265)
 *
 * Die Antwort fuer die Anzeige im Personen-Menue — in jedem Produkt dieselbe
 * Form, damit Manager und AI Brain dasselbe sagen. Jeder Eintrag ist ein
 * anderes System; eine leere Liste heisst „es gibt kein anderes System" und
 * die Oberflaeche zeigt dann nichts.
 *
 * Die Produkt-Seite liefert {@see BrueckenVerbindungsstand}: genau ein
 * Eintrag „AI Brain", sobald die Bruecke eingerichtet ist. Der Hub (AI Brain
 * selbst) bindet eine eigene Umsetzung, denn dort liegen die Produkte.
 *
 * @phpstan-type Eintrag array{system: string, verbunden: bool, text: string, url: string|null}
 */
interface Verbindungsstand
{
    /**
     * @return list<array{system: string, verbunden: bool, text: string, url: string|null}>
     */
    public function fuer(?Authenticatable $person): array;
}
