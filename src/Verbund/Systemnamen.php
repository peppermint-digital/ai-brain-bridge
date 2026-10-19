<?php

namespace Peppermint\AiBrainBridge\Verbund;

/**
 * Wie die Systeme heissen.
 *
 * Ueber die Leitung kommt ein Kuerzel (`peppermint-crm`), in der Liste soll
 * ein Wort stehen. Dieselbe Zuordnung in jedem System — wer eines
 * ergaenzt, ergaenzt sie ueberall.
 *
 * Ein fehlender Eintrag ist gutmuetig: Dann steht das Kuerzel da, und die
 * Zeilen bleiben erreichbar. Beim Ausrollen ist immer eines der vier
 * Systeme einen Schritt weiter, und in dieser Zeit soll nichts
 * verschwinden.
 *
 * ## Warum der Konfigurationsschluessel mitgegeben wird
 *
 * Damit mehrere Pakete dieselbe Mechanik benutzen koennen, ohne sich auf
 * EINE Konfigurationsdatei zu einigen. Die Liste steht dort, wo das
 * jeweilige Paket sie erwartet — die Aufloesung ist dieselbe.
 */
final class Systemnamen
{
    /** @return array{local: string, sources: array<string, string>} */
    public static function alle(string $konfig): array
    {
        return [
            'local' => self::eigenes($konfig),
            'sources' => self::fremde($konfig),
        ];
    }

    /** Wie dieses System sich selbst nennt. */
    public static function eigenes(string $konfig): string
    {
        $eigen = config($konfig.'.labels.local');

        return is_string($eigen) && $eigen !== '' ? $eigen : (string) config('app.name', 'Hier');
    }

    /** @return array<string, string> */
    public static function fremde(string $konfig): array
    {
        $liste = config($konfig.'.labels.sources', []);

        if (! is_array($liste)) {
            return [];
        }

        return array_filter(
            $liste,
            static fn ($wert, $key): bool => is_string($key) && is_string($wert),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
