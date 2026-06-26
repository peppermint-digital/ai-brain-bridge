<?php

namespace Peppermint\AiBrainBridge\Peer;

/**
 * Ergebnis einer Token-Ausstellung (Connector v2). `$plain` ist der Klartext-Token,
 * der dem Peer ins Bundle gelegt wird (nur einmal sichtbar). `$ref` ist eine
 * issuer-PRIVATE Referenz auf den dahinterliegenden nativen api.token (z.B. dessen
 * Modell-ID), damit `revoke()` ihn später wirklich invalidieren kann (#2583).
 * Die Ref verlässt das ausstellende Produkt NIE.
 */
final class PeerToken
{
    public function __construct(
        public readonly string $plain,
        public readonly ?string $ref = null,
    ) {}
}
