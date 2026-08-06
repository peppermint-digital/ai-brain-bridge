<?php

namespace Peppermint\AiBrainBridge\Peer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Symmetrischer Peer-to-Peer-Claim-Handshake (Connector-Plattform Phase 3, Track B).
 *
 * Jedes Produkt kann beides — OHNE AI Brain:
 *  - `issueClaim()`  → Code ausstellen, damit sich ein anderes Produkt mit MIR verbindet.
 *  - `redeem()`      → einen fremden Code einlösen und mich mit dem Peer verbinden.
 *  - `claim()`       → Endpoint-Seite: fremden Code gegen mein Bundle eintauschen.
 *  - `verifyInboundToken()` → für die Middleware: darf dieser Token mich rufen?
 *  - `revoke()` / `rotateInbound()` → Verbindung trennen bzw. Token erneuern (v2, #2583).
 *
 * Auth ist SDK-eigen (Bearer-Token gegen `peer_connectors`), damit es ohne
 * Eingriff in das produkt-spezifische api.token-System funktioniert.
 *
 * v2 (#2582/#2583): Verbindungen tragen optionale `scopes` (granulare Rechte) und
 * eine private Token-Referenz, mit der ein {@see RevocablePeerTokenIssuer} den
 * nativen api.token beim Trennen wirklich invalidieren kann.
 */
class PeerConnectionManager
{
    public const TTL_MINUTES = 15;

    /**
     * Code ausstellen. Liefert den Klartext-Code (nur EINMAL sichtbar). Der
     * eigentliche Peer-Token steckt verschlüsselt im Bundle und wird erst beim
     * Einlösen herausgegeben.
     *
     * @param  list<string>  $scopes  Granulare Rechte für die entstehende Verbindung.
     *                                 Leer = voller Zugriff (rückwärtskompatibel).
     */
    public function issueClaim(array $scopes = [], ?string $createdBy = null): string
    {
        // Token vom gebundenen Issuer (Default = SDK-Token; Produkt kann einen
        // nativen api.token-Issuer binden → gilt auf den bestehenden /api/*).
        $token = $this->issueToken();
        $code = Str::random(48);

        PeerClaimCode::create([
            'code_hash' => hash('sha256', $code),
            'bundle' => [
                'peer_slug' => (string) config('ai-brain-bridge.source'),
                'api_url' => $this->selfApiUrl(),
                'api_token' => $token->plain,
                // Signatur-Geheimnis dieser Verbindung (#540). Gehört der Richtung
                // „Peer ruft mich", nicht der Brain-Anbindung — deshalb hier und
                // nicht aus dem Event-Secret geliehen.
                'acting_secret' => self::newActingSecret(),
                'openapi_url' => config('ai-brain-bridge.peer.openapi_url'),
                'scopes' => array_values($scopes),
            ],
            // Issuer-PRIVAT: verlässt nie das Produkt, wird beim Einlösen an den
            // inbound-Connector vererbt, damit revoke() den Token killen kann.
            'issuer_ref' => $token->ref,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_by' => $createdBy,
        ]);

        return $code;
    }

    /**
     * Endpoint-Seite: fremder Code → mein Bundle (einmalig). Legt den inbound-
     * Connector an (wer darf mich rufen). `$fromSlug` = Slug des sich verbindenden
     * Produkts, damit die inbound-Verbindung in der UI benannt ist (nicht „—").
     * Null bei ungültig/abgelaufen/eingelöst.
     *
     * Die issuer-private Token-Referenz bleibt im Connector; das zurückgegebene
     * Bundle enthält sie NICHT.
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $code, ?string $fromSlug = null): ?array
    {
        $claim = PeerClaimCode::query()->where('code_hash', hash('sha256', $code))->first();

        if ($claim === null || ! $claim->isRedeemable()) {
            return null;
        }

        $claim->forceFill(['used_at' => now()])->save();
        $bundle = $claim->bundle;

        PeerConnector::create([
            'direction' => 'inbound',
            'peer_slug' => $fromSlug,
            'token_hash' => hash('sha256', (string) $bundle['api_token']),
            'acting_secret' => $bundle['acting_secret'] ?? null,
            'scopes' => $bundle['scopes'] ?? null,
            'issued_token_ref' => $claim->issuer_ref,
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
            $res = Http::acceptJson()->timeout(15)->post("{$base}/api/v1/connect/claim", [
                'code' => trim($code),
                // Eigenen Slug mitschicken, damit der Peer die eingehende Verbindung
                // benennen kann (sonst „—" in seiner UI).
                'peer_slug' => (string) config('ai-brain-bridge.source'),
            ]);
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
            // Gegenstück zum inbound-Connector des Peers: dieselbe Zufallszahl,
            // mit der ich meine Acting-User-Behauptungen an ihn signiere (#540).
            'acting_secret' => $b['acting_secret'] ?? null,
            'openapi_url' => $b['openapi_url'] ?? null,
            // Informativ: was DARF ich beim Peer (vom Peer erteilte Scopes).
            'scopes' => $b['scopes'] ?? null,
            'status' => 'active',
            'created_by' => $createdBy,
        ]);

        return ['ok' => true, 'peer_slug' => $b['peer_slug'] ?? null, 'error' => null];
    }

    /**
     * Aktiven inbound-Connector zu einem Token finden (oder null). Basis für
     * Auth UND Scope-Prüfung in der Middleware.
     */
    public function findInboundConnector(?string $token): ?PeerConnector
    {
        if (empty($token)) {
            return null;
        }

        return PeerConnector::query()
            ->where('direction', 'inbound')
            ->where('token_hash', hash('sha256', $token))
            ->active()
            ->first();
    }

    /**
     * Darf dieser (eingehende) Token mich aufrufen? Für die Peer-Auth-Middleware.
     */
    public function verifyInboundToken(?string $token): bool
    {
        return $this->findInboundConnector($token) !== null;
    }

    /**
     * Verbindung widerrufen (inbound: Token sperren; outbound: nicht mehr nutzen).
     * Wenn ein {@see RevocablePeerTokenIssuer} gebunden ist und der Connector eine
     * native Token-Referenz trägt, wird der zugrundeliegende api.token mit-invalidiert
     * (#2583 — schließt den v1-Caveat „Token überlebt das Trennen").
     */
    public function revoke(int $connectorId): bool
    {
        $connector = PeerConnector::find($connectorId);

        if ($connector === null) {
            return false;
        }

        $this->revokeNativeToken($connector);

        $connector->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        return true;
    }

    /**
     * Token einer inbound-Verbindung rotieren (#2583): neuen Token ausstellen,
     * einen frischen aktiven Connector mit denselben Scopes anlegen, den alten
     * widerrufen (inkl. nativem Token) und per `replaced_by_id` verketten.
     *
     * Gibt den NEUEN Token zurück (muss dem Peer übergeben werden — der alte Token
     * ist sofort tot). Null, wenn der Connector nicht existiert oder nicht inbound ist.
     */
    public function rotateInbound(int $connectorId): ?PeerToken
    {
        $old = PeerConnector::query()->where('direction', 'inbound')->find($connectorId);

        if ($old === null) {
            return null;
        }

        $token = $this->issueToken();

        $new = PeerConnector::create([
            'direction' => 'inbound',
            'peer_slug' => $old->peer_slug,
            'token_hash' => hash('sha256', $token->plain),
            'scopes' => $old->scopes,
            'issued_token_ref' => $token->ref,
            'status' => 'active',
            'created_by' => $old->created_by,
        ]);

        // Alten Token wirklich killen + Verbindung als ersetzt markieren.
        $this->revokeNativeToken($old);
        $old->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'replaced_by_id' => $new->id,
        ])->save();

        return $token;
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
            'scopes' => $c->scopes ?? [],
            'status' => $c->status,
            'replaced_by_id' => $c->replaced_by_id,
            'created_at' => $c->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Ein frisches Signatur-Geheimnis für eine Peer-Richtung.
     */
    public static function newActingSecret(): string
    {
        return Str::random(64);
    }

    /**
     * Das Geheimnis, mit dem ich meine Acting-User-Behauptungen an DIESEN Peer
     * signiere — und wenn es noch keines gibt, eines besorgen.
     *
     * Verbindungen aus der Zeit vor #540 tragen keines. Sie alle von Hand neu zu
     * verbinden wäre ein Flottenlauf; stattdessen legt der Anrufer beim ersten
     * signierten Aufruf ein Geheimnis an und übergibt es dem Peer über den
     * bereits token-authentifizierten Kanal. Danach ist Ruhe.
     *
     * Nimmt der Peer die Übergabe nicht an (altes Paket ⇒ 404), bleibt es beim
     * bisherigen Verhalten: null zurück, der Aufrufer signiert wie zuvor. So wird
     * unterwegs nichts schlechter, egal in welcher Reihenfolge ausgerollt wird.
     */
    public function actingSecretFor(PeerConnector $outbound): ?string
    {
        if ($outbound->hasActingSecret()) {
            return $outbound->acting_secret;
        }

        if ($outbound->direction !== 'outbound' || empty($outbound->api_url) || empty($outbound->api_token)) {
            return null;
        }

        $secret = self::newActingSecret();
        $url = rtrim((string) $outbound->api_url, '/').'/api/v1/peer/acting-secret';

        try {
            $res = Http::acceptJson()
                ->timeout(10)
                ->withToken((string) $outbound->api_token)
                ->post($url, ['acting_secret' => $secret]);
        } catch (\Throwable $e) {
            Log::warning('Peer-Signaturgeheimnis konnte nicht übergeben werden', [
                'peer' => $outbound->peer_slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Nicht auf HTTP 200 allein verlassen: Nur die Quittung des Endpunkts
        // beweist, dass der Peer das Geheimnis wirklich abgelegt hat. Sonst
        // koennte ein Catch-All eine Vereinbarung vortaeuschen, und ab da waere
        // jede Signatur ungueltig — mit demselben stillen 403 wie in #540.
        if (! $res->successful() || $res->json('peer_acting_secret') !== 'stored') {
            Log::info('Peer nimmt kein Signaturgeheimnis entgegen — Altverhalten bleibt', [
                'peer' => $outbound->peer_slug,
                'status' => $res->status(),
            ]);

            return null;
        }

        $outbound->forceFill(['acting_secret' => $secret])->save();

        return $secret;
    }

    /**
     * Endpoint-Seite der Übergabe: Der Peer, der mich mit diesem Token ruft,
     * hinterlegt das Geheimnis, mit dem er ab jetzt signiert.
     *
     * Bewusst überschreibend: Nur wer den gültigen Token dieser Verbindung hat,
     * kommt hier an — und das ist genau die Gegenstelle. So bleibt Rotation
     * möglich, ohne die Verbindung neu aufzubauen.
     */
    public function storeInboundActingSecret(?string $token, string $secret): bool
    {
        $connector = $this->findInboundConnector($token);

        if ($connector === null) {
            return false;
        }

        $connector->forceFill(['acting_secret' => $secret])->save();

        return true;
    }

    /**
     * API-Basis-URL DIESES Produkts (wohin Peers mich rufen).
     */
    public function selfApiUrl(): string
    {
        return rtrim((string) (config('ai-brain-bridge.peer.api_url') ?: config('app.url')), '/');
    }

    /**
     * Token vom gebundenen Issuer holen — mit Ref, falls der Issuer revocable ist.
     */
    private function issueToken(): PeerToken
    {
        $issuer = app(PeerTokenIssuer::class);

        if ($issuer instanceof RevocablePeerTokenIssuer) {
            return $issuer->issueWithRef();
        }

        return new PeerToken($issuer->issue());
    }

    /**
     * Den nativen api.token hinter einem Connector invalidieren, sofern möglich.
     */
    private function revokeNativeToken(PeerConnector $connector): void
    {
        $ref = $connector->issued_token_ref;

        if ($ref === null || $ref === '') {
            return;
        }

        $issuer = app(PeerTokenIssuer::class);

        if ($issuer instanceof RevocablePeerTokenIssuer) {
            $issuer->revokeByRef($ref);
        }
    }
}
