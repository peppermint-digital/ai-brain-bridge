<?php

namespace Peppermint\AiBrainBridge\Verbund;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\AiBrainManager;

/**
 * Sagt AI Brain, dass eine Person hier verbunden oder getrennt wurde (pm #10265).
 *
 * Die Wahrheit ist die Spalte im Produkt. AI Brain misst sie stuendlich ueber
 * `users.list`; dieses Ereignis ist der Anstoss dazwischen, damit die Anzeige
 * dort nicht bis zu einer Stunde nachlaeuft.
 *
 * ## Nach der Antwort, nie blockierend
 *
 * Wer auf „Verbinden" oder „Trennen" klickt, wartet nicht auf AI Brain. Das
 * Ereignis geht nach der Antwort raus; schlaegt es fehl, holt die stuendliche
 * Messung es nach. Deshalb wird ein Fehler protokolliert, nicht geworfen.
 */
class Kontomeldung
{
    public const ART = 'konto.verbindung';

    public static function melden(Authenticatable $person): void
    {
        if (! Bruecke::eingerichtet()) {
            return;
        }

        $nutzlast = self::nutzlast($person);

        // Einmal je Aufruf und Stand: Verbindet die Person ueber die Bruecke,
        // meldet der Controller — und ein Produkt mit eigenem Beobachter an
        // der Spalte ebenfalls. Gemerkt wird im Container, also je Anfrage.
        $schluessel = $nutzlast['product_user_id'].':'.($nutzlast['brain_user_id'] ?? '-');
        $gemeldet = app()->bound(self::class.'.gemeldet') ? app(self::class.'.gemeldet') : [];

        if (in_array($schluessel, $gemeldet, true)) {
            return;
        }

        app()->instance(self::class.'.gemeldet', [...$gemeldet, $schluessel]);

        dispatch(static function () use ($nutzlast): void {
            try {
                app(AiBrainManager::class)->emit(self::ART, $nutzlast, 'user:'.$nutzlast['product_user_id']);
            } catch (\Throwable $e) {
                Log::warning('Kontoverbindung liess sich AI Brain nicht melden — die stuendliche Messung holt es nach.', [
                    'fehler' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    /**
     * @return array{product_user_id: string, brain_user_id: string|null}
     */
    public static function nutzlast(Authenticatable $person): array
    {
        $spalte = (string) config('ai-brain-bridge.verbindung.spalte', 'ai_brain_user_id');
        $wert = $spalte !== '' && method_exists($person, 'getAttribute') ? $person->getAttribute($spalte) : null;

        return [
            'product_user_id' => (string) $person->getAuthIdentifier(),
            'brain_user_id' => filled($wert) ? (string) $wert : null,
        ];
    }
}
