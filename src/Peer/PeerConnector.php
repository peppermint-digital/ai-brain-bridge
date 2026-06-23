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
 */
class PeerConnector extends Model
{
    protected $fillable = [
        'direction', 'peer_slug', 'api_url', 'token_hash', 'api_token',
        'openapi_url', 'status', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null;
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<self>  $query */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')->whereNull('revoked_at');
    }
}
