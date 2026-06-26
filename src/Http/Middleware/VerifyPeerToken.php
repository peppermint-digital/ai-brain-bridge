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
 *
 * v2 (#2582): Optionaler Scope-Parameter erzwingt granulare Rechte, z.B.
 * `->middleware('peer.auth:contacts.read')`. Connectors ohne erteilte Scopes
 * haben vollen Zugriff (rückwärtskompatibel zum bisherigen Verhalten).
 */
class VerifyPeerToken
{
    public function __construct(private readonly PeerConnectionManager $peers) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $connector = $this->peers->findInboundConnector($request->bearerToken());

        if ($connector === null) {
            throw new AccessDeniedHttpException('Ungültiger oder widerrufener Peer-Token.');
        }

        if ($scope !== null && ! $connector->allowsScope($scope)) {
            throw new AccessDeniedHttpException("Peer-Token fehlt der Scope: {$scope}");
        }

        return $next($request);
    }
}
