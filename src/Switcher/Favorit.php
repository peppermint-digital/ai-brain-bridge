<?php

namespace Peppermint\AiBrainBridge\Switcher;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Favorit (Stern) der Umschaltleiste, im Produkt gespeichert (pm #10219).
 *
 * @property int $id
 * @property int $user_id
 * @property string $url
 * @property string $url_hash
 * @property string $label
 * @property ?string $symbol
 * @property int $position
 */
class Favorit extends Model
{
    protected $table = 'switcher_pins';

    protected $fillable = [
        'user_id',
        'url',
        'url_hash',
        'label',
        'symbol',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public static function fingerabdruck(string $url): string
    {
        return hash('sha256', $url);
    }
}
