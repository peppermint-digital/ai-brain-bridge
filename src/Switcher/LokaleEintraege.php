<?php

namespace Peppermint\AiBrainBridge\Switcher;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Eintraege, die das PRODUKT der Umschaltleiste beisteuert — neben den
 * Systemen, die AI Brain aus dem Verzeichnis liefert.
 *
 * Gedacht fuer Teile eines Systems, zwischen denen man wechselt wie zwischen
 * Systemen: die Module des Managers (Manager, Personal, Betriebsmittel). Was
 * eine Person davon sieht, weiss nur das Produkt (Lizenz und Rechte) — AI
 * Brain kennt seine Module nicht und soll es auch nicht.
 *
 * ## Freiwillig
 *
 * Ohne Bindung fragt die Leiste nicht danach, und alles bleibt, wie es war:
 * Antwort und Markup tragen dann keinen Schluessel `lokal`. Ein Produkt
 * bindet den Vertrag in seinem ServiceProvider:
 *
 *     $this->app->bind(LokaleEintraege::class, ModulEintraege::class);
 *
 * ## Wie die Leiste sie zeigt
 *
 * Sie nehmen den Platz des EIGENEN Systems ein (des Eintrags, den AI Brain mit
 * `aktuell` markiert) — sonst stuende das System neben seinen eigenen Teilen.
 * Nicht-aktive Eintraege sind gewoehnliche Links auf `url`. Das aktive ist
 * markiert und ohne Link.
 *
 * Welcher aktiv ist, sagt `aktuell` beim Seitenaufruf. Weil eine
 * Inertia-Anwendung beim Navigieren das Layout mit der Leiste nicht neu
 * schreibt, darf das Produkt das zur Laufzeit ueberschreiben: Attribut
 * `lokal-aktiv="<schluessel>"` am Element `<peppermint-app-switcher>`.
 *
 * ## Nie im Zwischenspeicher
 *
 * Die Eintraege werden bei JEDEM Aufruf frisch erfragt und nie mit der
 * Antwort von AI Brain zwischengespeichert: Wird jemandem ein Modul entzogen,
 * muss es beim naechsten Laden aus der Leiste verschwinden, nicht nach fuenf
 * Minuten. Die Methode wird deshalb auf jeder Seite gerufen — sie darf kein
 * Netz brauchen.
 */
interface LokaleEintraege
{
    /**
     * @return list<array{
     *     schluessel: string,
     *     name: string,
     *     url: string,
     *     kuerzel: string,
     *     farbe?: string,
     *     symbol?: string,
     *     aktuell?: bool,
     * }>
     *
     * `symbol` ist ein Schluessel aus der Symbolliste der Leiste (`uhr`,
     * `paket`, `gruppe` …), kein Markup. Ein unbekannter Schluessel faellt
     * auf das Kuerzel zurueck.
     */
    public function fuer(Authenticatable $person, Request $anfrage): array;
}
