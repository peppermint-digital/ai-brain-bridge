<?php

namespace Peppermint\AiBrainBridge\Peer;

use Illuminate\Support\Facades\Cache;
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
    /** Cache-Dauer für entdeckte Capabilities (Sekunden). */
    public const CAPABILITIES_TTL = 3600;

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

    /**
     * Capability-Discovery (#2584): Welche Operationen bietet ein verbundener Peer?
     * Mechanismus = die beim Connect erhaltene OpenAPI-URL (im Bundle/Connector).
     * Ergebnis wird gecached, damit nicht jeder Aufruf die Spec lädt.
     *
     * `$paths` liefert kompakt die Liste „METHOD /pfad" aus der OpenAPI-Spec, mit
     * der ein Produkt (oder Mensch) sieht, was beim Peer erlaubt/möglich ist.
     *
     * @return array{ok: bool, error: string|null, openapi_url: string|null, paths: list<string>, spec: mixed}
     */
    public function capabilities(string $peerSlug, bool $fresh = false): array
    {
        $connector = PeerConnector::query()
            ->where('direction', 'outbound')
            ->where('peer_slug', $peerSlug)
            ->active()
            ->first();

        if ($connector === null) {
            return ['ok' => false, 'error' => "Kein aktiver Connector zu {$peerSlug}", 'openapi_url' => null, 'paths' => [], 'spec' => null];
        }

        $openapiUrl = $connector->openapi_url;

        if (empty($openapiUrl)) {
            return ['ok' => false, 'error' => "Peer {$peerSlug} liefert keine OpenAPI-URL", 'openapi_url' => null, 'paths' => [], 'spec' => null];
        }

        $cacheKey = 'ai-brain-bridge:peer-capabilities:'.$peerSlug;

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CAPABILITIES_TTL, function () use ($openapiUrl): array {
            try {
                $res = Http::acceptJson()->timeout($this->timeoutSeconds)->get($openapiUrl);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'openapi_url' => $openapiUrl, 'paths' => [], 'spec' => null];
            }

            if (! $res->successful()) {
                return ['ok' => false, 'error' => "HTTP {$res->status()}", 'openapi_url' => $openapiUrl, 'paths' => [], 'spec' => null];
            }

            $spec = $res->json();

            return [
                'ok' => true,
                'error' => null,
                'openapi_url' => $openapiUrl,
                'paths' => $this->extractPaths($spec),
                'spec' => $spec,
            ];
        });
    }

    /**
     * „METHOD /pfad"-Liste aus einer OpenAPI-Spec ziehen (defensiv gegen
     * unerwartete Strukturen).
     *
     * @param  mixed  $spec
     * @return list<string>
     */
    private function extractPaths($spec): array
    {
        if (! is_array($spec) || ! isset($spec['paths']) || ! is_array($spec['paths'])) {
            return [];
        }

        $out = [];

        foreach ($spec['paths'] as $path => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach (array_keys($operations) as $method) {
                if (in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $out[] = strtoupper((string) $method).' '.$path;
                }
            }
        }

        sort($out);

        return $out;
    }
}
