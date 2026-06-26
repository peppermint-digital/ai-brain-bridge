<?php

namespace Peppermint\AiBrainBridge\Peer;

/**
 * Optionale Erweiterung von {@see PeerTokenIssuer} (Connector v2, #2583). Ein Produkt
 * bindet diese Variante, wenn sein nativer api.token nach dem Trennen einer Peer-
 * Verbindung wirklich ungültig werden soll — statt nur die Peer-Verbindung zu markieren.
 *
 *  - `issueWithRef()` stellt den Token aus UND liefert eine private Referenz zurück
 *    (z.B. die ApiToken-Modell-ID), die im ausstellenden Produkt gespeichert wird.
 *  - `revokeByRef()`  invalidiert den so referenzierten nativen Token (löscht/sperrt ihn).
 *
 * Ist kein revocable Issuer gebunden, bleibt das alte Verhalten: `revoke()` markiert
 * nur die Verbindung, der zugrundeliegende Token muss separat im API-Tokens-Admin
 * entfernt werden (v1-Caveat).
 */
interface RevocablePeerTokenIssuer extends PeerTokenIssuer
{
    public function issueWithRef(): PeerToken;

    public function revokeByRef(string $ref): void;
}
