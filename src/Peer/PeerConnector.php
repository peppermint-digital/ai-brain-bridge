<?php

namespace Peppermint\AiBrainBridge\Peer;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine aktive Peer-Verbindung (Phase 3, Spec #249).
 *  - direction=inbound:  jemand darf MICH rufen (token_hash = Hash seines Tokens).
 *  - direction=outbound: ICH rufe einen Peer (api_url + verschlüsseltes api_token).
 *
 * @property string $direction
 * @property string|null $api_token
 * @property array<int, string>|null $scopes
 * @property string|null $issued_token_ref
 * @property int|null $replaced_by_id
 */
class PeerConnector extends Model
{
    protected $fillable = [
        'direction', 'peer_slug', 'api_url', 'token_hash', 'api_token',
        'acting_secret', 'openapi_url', 'scopes', 'issued_token_ref',
        'replaced_by_id', 'status', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'acting_secret' => 'encrypted',
            'scopes' => 'array',
            'issued_token_ref' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Signatur-Geheimnis DIESER Verbindung (Bug #540). Leer bei Verbindungen aus
     * der Zeit davor — dann greift der Alt-Weg über das Brain-Event-Secret,
     * bis sich die Verbindung beim ersten signierten Aufruf selbst versorgt.
     */
    public function hasActingSecret(): bool
    {
        return is_string($this->acting_secret) && $this->acting_secret !== '';
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null;
    }

    /**
     * Darf diese Verbindung die gegebene Operation? Leere/keine Scopes = voller
     * Zugriff (rückwärtskompatibel). Sonst muss der Scope explizit erteilt sein.
     */
    public function allowsScope(string $scope): bool
    {
        if (empty($this->scopes)) {
            return true;
        }

        return in_array($scope, $this->scopes, true);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<self>  $query */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')->whereNull('revoked_at');
    }
}
