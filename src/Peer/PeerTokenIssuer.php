<?php

namespace Peppermint\AiBrainBridge\Peer;

/**
 * Stellt den Token aus, mit dem ein verbundener Peer DIESES Produkt aufruft
 * (Phase 3b, Migration des bespoke-Mesh). Ein Produkt bindet das an SEIN eigenes
 * api.token-System (`set-credential`/ApiToken-Modell) — dann ist der Peer-Token
 * ein NATIVER api.token, den die bestehenden `/api/*`-Endpoints (ValidateApiKey)
 * unverändert akzeptieren. So lösen wir die alten statischen `.env`-Tokens ab,
 * ohne die Endpoints anzufassen.
 *
 * Ohne Bindung greift {@see DefaultPeerTokenIssuer} (SDK-eigener Token, nur über
 * die `peer.auth`-Middleware gültig).
 */
interface PeerTokenIssuer
{
    /** Klartext-Token (nur einmal verfügbar), gültig auf den eigenen Endpoints. */
    public function issue(): string;
}
