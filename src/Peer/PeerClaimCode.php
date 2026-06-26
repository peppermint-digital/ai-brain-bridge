<?php

namespace Peppermint\AiBrainBridge\Peer;

use Illuminate\Database\Eloquent\Model;

/**
 * Von DIESEM Produkt ausgestellter, einmalig einlösbarer Peer-Claim-Code
 * (Phase 3, Spec #249). Der Klartext-Code wird nie gespeichert (nur sein Hash);
 * das Bundle (api_token, api_url) liegt verschlüsselt.
 *
 * @property string $code_hash
 * @property array<string, mixed> $bundle
 * @property string|null $issuer_ref
 */
class PeerClaimCode extends Model
{
    protected $fillable = [
        'code_hash', 'bundle', 'issuer_ref', 'expires_at', 'used_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bundle' => 'encrypted:array',
            'issuer_ref' => 'encrypted',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isRedeemable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
