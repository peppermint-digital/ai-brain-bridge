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
        'openapi_url', 'scopes', 'issued_token_ref', 'replaced_by_id',
        'status', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'scopes' => 'array',
            'issued_token_ref' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
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
