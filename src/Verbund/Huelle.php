<?php

namespace Peppermint\AiBrainBridge\Verbund;

/**
 * Die Nutzlast aus der Antwort eines MCP-Werkzeugs schaelen.
 *
 * ## Warum das geteilt gehoert
 *
 * Dieselbe Nutzlast kommt je nach Weg in verschiedenen Huellen an —
 * `data`, `structuredContent`, `content`, `result` oder gleich nackt. Das
 * ist nicht schoen, aber es ist die Wirklichkeit der Leitung.
 *
 * Entscheidend ist, was bei einem Fehlgriff passiert: Wer die Huelle nicht
 * findet, bekommt eine LEERE Liste und keinen Fehler. Das sieht aus wie
 * „das andere System hat nichts" und ist in Wahrheit „wir haben nicht
 * hingesehen".
 *
 * Genau deshalb darf es diese Logik nur EINMAL geben. Eine zweite Kopie
 * veraltet still, und zwar in der Richtung, die niemand bemerkt.
 */
final class Huelle
{
    /**
     * Die Huelle finden, die den erwarteten Schluessel enthaelt.
     *
     * @param  array<string, mixed>  $antwort
     * @param  string  $erwartet  Der Schluessel, an dem man die Nutzlast erkennt.
     * @return array<string, mixed>
     */
    public static function schaelen(array $antwort, string $erwartet = 'items'): array
    {
        $kandidaten = [
            $antwort,
            $antwort['data'] ?? null,
            $antwort['structuredContent'] ?? null,
            $antwort['content'] ?? null,
            $antwort['result'] ?? null,
            $antwort['antwort'] ?? null,
        ];

        foreach ($kandidaten as $kandidat) {
            if (is_array($kandidat) && isset($kandidat[$erwartet]) && is_array($kandidat[$erwartet])) {
                return $kandidat;
            }
        }

        return [];
    }

    /**
     * Eine Liste von Zeilen aus der Nutzlast — ohne das, was keine ist.
     *
     * @param  array<string, mixed>  $huelle
     * @return array<int, array<string, mixed>>
     */
    public static function zeilen(array $huelle, string $schluessel = 'items'): array
    {
        return array_values(array_filter(
            (array) ($huelle[$schluessel] ?? []),
            static fn ($zeile): bool => is_array($zeile),
        ));
    }

    /**
     * Eine Liste von Woertern aus der Nutzlast.
     *
     * @param  array<string, mixed>  $huelle
     * @return array<int, string>
     */
    public static function woerter(array $huelle, string $schluessel): array
    {
        return array_values(array_filter(
            (array) ($huelle[$schluessel] ?? []),
            static fn ($wert): bool => is_string($wert) && $wert !== '',
        ));
    }
}
