<?php

namespace Peppermint\AiBrainBridge\Connect;

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Config\BridgeConfig;

/**
 * One-Click-Anbindungslogik (Connector-Plattform Phase 1/2, Spec #249) — EINE
 * Quelle für CLI (`ai-brain:connect`) UND die HTTP-Connect-Route (Phase 2).
 * Löst einen Claim-Code am Hub ein und persistiert das Bundle im Settings-Store.
 */
class Connector
{
    /**
     * Claim-Code einlösen und die Anbindung persistieren.
     *
     * @return array{ok: bool, product_slug: string|null, error: string|null}
     */
    public function connect(string $code, ?string $url = null): array
    {
        $base = rtrim((string) ($url ?: config('ai-brain-bridge.base_url')), '/');

        if ($base === '') {
            return ['ok' => false, 'product_slug' => null, 'error' => 'Keine Brain-URL konfiguriert.'];
        }

        try {
            $res = Http::acceptJson()->timeout(15)->post("{$base}/api/v1/connect/claim", ['code' => trim($code)]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'product_slug' => null, 'error' => $e->getMessage()];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'product_slug' => null, 'error' => $res->json('message') ?? "HTTP {$res->status()}"];
        }

        $b = (array) $res->json();

        BridgeConfig::save([
            'base_url' => $b['brain_url'] ?? $base,
            'source' => $b['product_slug'] ?? config('ai-brain-bridge.source'),
            'oauth' => [
                'client_id' => $b['client_id'] ?? null,
                'client_secret' => $b['client_secret'] ?? null,
                'scope' => $b['scope'] ?? 'mcp:use',
            ],
            'mcp' => ['brain_url' => $b['brain_mcp_url'] ?? null],
            'events' => [
                'endpoint' => $b['events_url'] ?? null,
                'secret' => $b['event_secret'] ?? null,
            ],
            'jwt_public_key_url' => $b['jwt_public_key_url'] ?? null,
        ]);

        BridgeConfig::apply();

        return ['ok' => true, 'product_slug' => $b['product_slug'] ?? null, 'error' => null];
    }

    /**
     * Anbindungsstatus (ohne Secrets) — für die Produkt-UI.
     *
     * @return array{connected: bool, source: string|null, base_url: string|null}
     */
    public function status(): array
    {
        return [
            'connected' => BridgeConfig::load() !== null && ! empty(config('ai-brain-bridge.oauth.client_id')),
            'source' => config('ai-brain-bridge.source'),
            'base_url' => config('ai-brain-bridge.base_url'),
        ];
    }
}
