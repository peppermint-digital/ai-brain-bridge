<?php

namespace Peppermint\AiBrainBridge\Config;

/**
 * Persistenter Settings-Store für die One-Click-Anbindung (Connector-Plattform
 * Phase 1, Spec #249). `ai-brain:connect` schreibt das vom Hub bezogene Bundle
 * (client_id/secret, event_secret, urls) hierher; `apply()` legt es bei jedem
 * Boot über die ENV-Defaults. So funktioniert die Anbindung OHNE manuelles
 * `.env`-Editieren — ENV bleibt Fallback/Override-Bootstrap.
 *
 * Format = dieselbe Form wie config('ai-brain-bridge') (base_url, source,
 * oauth{}, mcp{}, events{}, jwt_public_key_url).
 */
class BridgeConfig
{
    public static function path(): string
    {
        return (string) config('ai-brain-bridge.store_path');
    }

    /**
     * Gespeichertes Bundle laden (oder null, wenn nicht angebunden).
     *
     * @return array<string, mixed>|null
     */
    public static function load(): ?array
    {
        $path = self::path();

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    /**
     * Bundle verschlüsselungsfrei, aber dateirechte-restriktiv (0600) ablegen —
     * gleiche Vertraulichkeitsstufe wie `.env`.
     *
     * @param  array<string, mixed>  $bundle
     */
    public static function save(array $bundle): void
    {
        $path = self::path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        file_put_contents($path, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    /**
     * Gespeichertes Bundle über die laufende Config legen (no-op wenn nicht
     * angebunden). ENV-Defaults bleiben, wo der Store nichts setzt.
     */
    public static function apply(): void
    {
        $stored = self::load();

        if ($stored === null) {
            return;
        }

        $merged = array_replace_recursive((array) config('ai-brain-bridge'), $stored);
        config(['ai-brain-bridge' => $merged]);
    }
}
