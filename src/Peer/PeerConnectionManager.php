<?php

namespace Peppermint\AiBrainBridge\Peer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Symmetrischer Peer-to-Peer-Claim-Handshake (Connector-Plattform Phase 3, Track B).
 *
 * Jedes Produkt kann beides — OHNE AI Brain:
 *  - `issueClaim()`  → Code ausstellen, damit sich ein anderes Produkt mit MIR verbindet.
 *  - `redeem()`      → einen fremden Code einlösen und mich mit dem Peer verbinden.
 *  - `claim()`       → Endpoint-Seite: fremden Code gegen mein Bundle eintauschen.
 *  - `verifyInboundToken()` → für die Middleware: darf dieser Token mich rufen?
 *
 * Auth ist SDK-eigen (Bearer-Token gegen `peer_connectors`), damit es ohne
 * Eingriff in das produkt-spezifische api.token-System funktioniert.
 */
class PeerConnectionManager
{
    public const TTL_MINUTES = 15;

    /**
     * Code ausstellen. Liefert den Klartext-Code (nur EINMAL sichtbar). Der
     * eigentliche Peer-Token steckt verschlüsselt im Bundle und wird erst beim
     * Einlösen herausgegeben.
     */
    public function issueClaim(?string $createdBy = null): string
    {
        $token = Str::random(48);
        $code = Str::random(48);

        PeerClaimCode::create([
            'code_hash' => hash('sha256', $code),
            'bundle' => [
                'peer_slug' => (string) config('ai-brain-bridge.source'),
                'api_url' => $this->selfApiUrl(),
                'api_token' => $token,
                'openapi_url' => config('ai-brain-bridge.peer.openapi_url'),
            ],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_by' => $createdBy,
        ]);

        return $code;
    }

    /**
     * Endpoint-Seite: fremder Code → mein Bundle (einmalig). Legt den inbound-
     * Connector an (wer darf mich rufen). Null bei ungültig/abgelaufen/eingelöst.
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $code): ?array
    {
        $claim = PeerClaimCode::query()->where('code_hash', hash('sha256', $code))->first();

        if ($claim === null || ! $claim->isRedeemable()) {
            return null;
        }

        $claim->forceFill(['used_at' => now()])->save();
        $bundle = $claim->bundle;

        PeerConnector::create([
            'direction' => 'inbound',
            'token_hash' => hash('sha256', (string) $bundle['api_token']),
            'status' => 'active',
        ]);

        return $bundle;
    }

    /**
     * Einlösen: fremden Code beim Peer eintauschen und mich mit ihm verbinden
     * (outbound-Connector). Brain ist NICHT beteiligt.
     *
     * @return array{ok: bool, peer_slug: string|null, error: string|null}
     */
    public function redeem(string $peerUrl, string $code, ?string $createdBy = null): array
    {
        $base = rtrim($peerUrl, '/');

        try {
            $res = Http::acceptJson()->timeout(15)->post("{$base}/api/v1/connect/claim", ['code' => trim($code)]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'peer_slug' => null, 'error' => $e->getMessage()];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'peer_slug' => null, 'error' => $res->json('message') ?? "HTTP {$res->status()}"];
        }

        $b = (array) $res->json();

        PeerConnector::create([
            'direction' => 'outbound',
            'peer_slug' => $b['peer_slug'] ?? null,
            'api_url' => $b['api_url'] ?? $base,
            'api_token' => $b['api_token'] ?? null,
            'openapi_url' => $b['openapi_url'] ?? null,
            'status' => 'active',
            'created_by' => $createdBy,
        ]);

        return ['ok' => true, 'peer_slug' => $b['peer_slug'] ?? null, 'error' => null];
    }

    /**
     * Darf dieser (eingehende) Token mich aufrufen? Für die Peer-Auth-Middleware.
     */
    public function verifyInboundToken(?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        return PeerConnector::query()
            ->where('direction', 'inbound')
            ->where('token_hash', hash('sha256', $token))
            ->active()
            ->exists();
    }

    /**
     * Verbindung widerrufen (inbound: Token sperren; outbound: nicht mehr nutzen).
     */
    public function revoke(int $connectorId): bool
    {
        $connector = PeerConnector::find($connectorId);

        if ($connector === null) {
            return false;
        }

        $connector->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        return true;
    }

    /**
     * Eigene Verbindungen (ohne Secrets) — für die Produkt-UI.
     *
     * @return list<array<string, mixed>>
     */
    public function peers(): array
    {
        return PeerConnector::query()->orderByDesc('id')->get()->map(fn (PeerConnector $c): array => [
            'id' => $c->id,
            'direction' => $c->direction,
            'peer_slug' => $c->peer_slug,
            'api_url' => $c->api_url,
            'status' => $c->status,
            'created_at' => $c->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * API-Basis-URL DIESES Produkts (wohin Peers mich rufen).
     */
    public function selfApiUrl(): string
    {
        return rtrim((string) (config('ai-brain-bridge.peer.api_url') ?: config('app.url')), '/');
    }
}
