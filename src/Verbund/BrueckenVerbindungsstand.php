<?php

namespace Peppermint\AiBrainBridge\Verbund;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\Kontoverbindung;

/**
 * Der Verbindungsstand eines Produkts gegenueber AI Brain (pm #10265).
 *
 * Die Frage selbst beantwortet {@see Kontoverbindung} — dieselbe Stelle, an
 * der Gate, Fassade und Leiste haengen. Hier kommt nur hinzu, was die Anzeige
 * braucht: Name, Satz und Ziel des Klicks.
 *
 * Ohne eingerichtete Bruecke gibt es kein anderes System: leere Liste.
 */
class BrueckenVerbindungsstand implements Verbindungsstand
{
    public function __construct(private readonly Kontoverbindung $verbindung) {}

    public function fuer(?Authenticatable $person): array
    {
        if ($person === null || ! Bruecke::eingerichtet()) {
            return [];
        }

        $system = (string) config('ai-brain-bridge.verbindung.anzeige_name', 'AI Brain');
        $verbunden = $this->verbindung->istVerbunden($person);

        return [[
            'system' => $system,
            'verbunden' => $verbunden,
            'text' => $verbunden ? "Mit {$system} verbunden" : "Mit {$system} verbinden",
            'url' => $this->einstellungen(),
        ]];
    }

    private function einstellungen(): ?string
    {
        $route = (string) config('ai-brain-bridge.verbindung.einstellungen_route', 'settings.ai-brain');

        return $route !== '' && Route::has($route) ? route($route, absolute: false) : null;
    }
}
