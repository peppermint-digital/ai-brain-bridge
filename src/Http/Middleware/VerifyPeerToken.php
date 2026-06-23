<?php

namespace Peppermint\AiBrainBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Peppermint\AiBrainBridge\Peer\PeerConnectionManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Schützt Endpoints, die DIESES Produkt für verbundene Peers freigibt
 * (Phase 3, Track B). Validiert den Bearer-Token gegen die aktiven inbound-
 * Connectors — widerrufene/abgelaufene Tokens fliegen raus. Kein Brain nötig.
 */
class VerifyPeerToken
{
    public function __construct(private readonly PeerConnectionManager $peers) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->peers->verifyInboundToken($request->bearerToken())) {
            throw new AccessDeniedHttpException('Ungültiger oder widerrufener Peer-Token.');
        }

        return $next($request);
    }
}
