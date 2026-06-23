<?php

namespace Peppermint\AiBrainBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\AiBrainBridge\Peer\PeerConnectionManager;

/**
 * Peer-to-Peer-Connect-Endpoints (Phase 3, Track B). Vom SDK bereitgestellt,
 * vom Produkt freigeschaltet + admin-gegatet. Kein AI Brain beteiligt.
 *
 *  - claim()   : öffentlich (Code = Secret, gethrottelt) — fremder Code → mein Bundle.
 *  - issue()   : Admin — Code ausstellen, damit sich ein Peer mit mir verbindet.
 *  - connect() : Admin — fremden Code + Peer-URL einlösen.
 *  - index()   : Admin — eigene Verbindungen.
 *  - revoke()  : Admin — Verbindung widerrufen.
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

    public function issue(Request $request): JsonResponse
    {
        $by = $request->user() ? (string) $request->user()->getAuthIdentifier() : null;

        return response()->json([
            'code' => $this->peers->issueClaim($by),
            'expires_in_minutes' => PeerConnectionManager::TTL_MINUTES,
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'peer_url' => ['required', 'string', 'url'],
            'code' => ['required', 'string', 'min:16', 'max:128'],
        ]);

        $by = $request->user() ? (string) $request->user()->getAuthIdentifier() : null;
        $result = $this->peers->redeem($data['peer_url'], $data['code'], $by);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function index(): JsonResponse
    {
        return response()->json(['peers' => $this->peers->peers()]);
    }

    public function revoke(int $connector): JsonResponse
    {
        $ok = $this->peers->revoke($connector);

        return response()->json(['ok' => $ok], $ok ? 200 : 404);
    }
}
