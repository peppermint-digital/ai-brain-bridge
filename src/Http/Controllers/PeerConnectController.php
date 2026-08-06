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
        $data = $request->validate([
            'code' => ['required', 'string', 'min:16', 'max:128'],
            'peer_slug' => ['nullable', 'string', 'max:100'],
        ]);

        $bundle = $this->peers->claim($data['code'], $data['peer_slug'] ?? null);

        if ($bundle === null) {
            return response()->json(['message' => 'Ungültiger, abgelaufener oder bereits eingelöster Code.'], 422);
        }

        return response()->json($bundle);
    }

    /**
     * Der verbundene Peer hinterlegt das Geheimnis, mit dem er seine
     * Acting-User-Behauptungen ab jetzt signiert (#540).
     *
     * Hinter `peer.auth`: Nur wer den gültigen Token dieser Verbindung besitzt,
     * kommt hier an — dieselbe Vertrauensgrenze, die auch alle anderen
     * Peer-Aufrufe traegt. Gedacht fuer Verbindungen, die vor #540 entstanden
     * sind und noch kein eigenes Geheimnis haben; neue bekommen es im Bundle.
     */
    public function actingSecret(Request $request): JsonResponse
    {
        $data = $request->validate([
            'acting_secret' => ['required', 'string', 'min:32', 'max:255'],
        ]);

        $stored = $this->peers->storeInboundActingSecret($request->bearerToken(), $data['acting_secret']);

        if (! $stored) {
            // Entweder gibt es keine passende Verbindung — oder sie hat bereits
            // ein Geheimnis. Beides ist eine Absage, und beide Faelle absichtlich
            // ununterscheidbar: ein Angreifer mit erbeutetem Token soll hier
            // nichts ueber den Zustand der Verbindung lernen.
            return response()->json(['message' => 'Kein Geheimnis hinterlegt.'], 422);
        }

        // Die Quittung ist bewusst ein eigenes Merkmal und nicht bloss HTTP 200:
        // Der Anrufer darf sein Geheimnis nur dann als vereinbart ablegen, wenn
        // WIRKLICH diese Stelle geantwortet hat. Ein freundlicher Catch-All oder
        // ein Proxy, der auf alles mit 200 antwortet, wuerde sonst eine
        // Vereinbarung vortaeuschen, die nie zustande kam — und ab da schluege
        // jede Signatur fehl.
        return response()->json(['status' => 'ok', 'peer_acting_secret' => 'stored']);
    }
}
