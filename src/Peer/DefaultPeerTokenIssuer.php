<?php

namespace Peppermint\AiBrainBridge\Peer;

use Illuminate\Support\Str;

/**
 * Standard-Aussteller: ein SDK-eigener Zufalls-Token. Gültig NUR über die
 * `peer.auth`-Middleware (Hash in `peer_connectors`). Genug für SDK-eigene
 * Peer-Endpoints (z.B. /api/v1/peer/ping). Für Zugriff auf die bestehende
 * `/api/*`-API bindet ein Produkt einen eigenen, nativen Issuer (Phase 3b).
 */
class DefaultPeerTokenIssuer implements PeerTokenIssuer
{
    public function issue(): string
    {
        return Str::random(48);
    }
}
