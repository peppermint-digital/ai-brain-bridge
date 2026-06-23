<?php

namespace Peppermint\AiBrainBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\AiBrainBridge\Peer\PeerConnectionManager;

/**
 * Öffentlicher Peer-Claim-Endpoint (Phase 3, Track B): ein fremdes Produkt löst
 * hier seinen Code gegen mein Bundle ein. Code = Secret → kein Auth, aber
 * gethrottelt. Vom SDK registriert, wenn `peer.enabled`. Kein AI Brain beteiligt.
 *
 * Die ADMIN-Aktionen (ausstellen/verbinden/liste/widerrufen) baut jedes Produkt
 * selbst hinter seinem Admin-Gate über {@see PeerConnectionManager}.
 */
class PeerConnectController
{
    public function __construct(private readonly PeerConnectionManager $peers) {}

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:16', 'max:128']]);

        $bundle = $this->peers->claim($data['code']);

        if ($bundle === null) {
            return response()->json(['message' => 'Ungültiger, abgelaufener oder bereits eingelöster Code.'], 422);
        }

        return response()->json($bundle);
    }
}
