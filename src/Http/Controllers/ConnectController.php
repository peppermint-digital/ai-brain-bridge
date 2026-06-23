<?php

namespace Peppermint\AiBrainBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\AiBrainBridge\Connect\Connector;

/**
 * Frontend-agnostischer Connect-Endpoint für die One-Click-Anbindung
 * (Connector-Plattform Phase 2, Spec #249). Jedes Produkt hängt seinen eigenen
 * „Mit AI Brain verbinden"-Button an diese Route.
 *
 * Sicherheit liegt beim PRODUKT: Die Route ist per Default AUS und wird nur über
 * `config('ai-brain-bridge.connect.middleware')` (z.B. ['web','auth','can:admin'])
 * vom Produkt freigeschaltet — sie schreibt Credentials.
 */
class ConnectController
{
    public function __construct(private readonly Connector $connector) {}

    public function status(): JsonResponse
    {
        return response()->json($this->connector->status());
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:16', 'max:128'],
            'url' => ['nullable', 'string', 'url'],
        ]);

        $result = $this->connector->connect($data['code'], $data['url'] ?? null);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
