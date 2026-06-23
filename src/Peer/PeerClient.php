<?php

namespace Peppermint\AiBrainBridge\Peer;

use Illuminate\Support\Facades\Http;

/**
 * Generischer REST-Client, mit dem DIESES Produkt einen verbundenen Peer direkt
 * aufruft (Phase 3, Track B) — mit dem beim Connect erhaltenen api.token, ohne
 * AI Brain im Pfad.
 *
 *   app(PeerClient::class)->call('peppermint-crm', 'GET', '/api/v1/contacts');
 */
class PeerClient
{
    public function __construct(private int $timeoutSeconds = 15) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, status: int, error: string|null, data: mixed}
     */
    public function call(string $peerSlug, string $method, string $path, array $data = []): array
    {
        $connector = PeerConnector::query()
            ->where('direction', 'outbound')
            ->where('peer_slug', $peerSlug)
            ->active()
            ->first();

        if ($connector === null) {
            return ['ok' => false, 'status' => 0, 'error' => "Kein aktiver Connector zu {$peerSlug}", 'data' => null];
        }

        $url = rtrim((string) $connector->api_url, '/').'/'.ltrim($path, '/');

        try {
            $req = Http::acceptJson()->timeout($this->timeoutSeconds)->withToken((string) $connector->api_token);

            $res = match (strtoupper($method)) {
                'GET' => $req->get($url, $data),
                'DELETE' => $req->delete($url, $data),
                'PUT' => $req->put($url, $data),
                'PATCH' => $req->patch($url, $data),
                default => $req->post($url, $data),
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage(), 'data' => null];
        }

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'error' => $res->successful() ? null : ($res->json('message') ?? "HTTP {$res->status()}"),
            'data' => $res->json(),
        ];
    }
}
