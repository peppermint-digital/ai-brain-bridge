<?php

namespace Peppermint\AiBrainBridge\Switcher;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Config\BridgeConfig;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Was AI Brain fuer eine Person zur Leiste beitraegt: Systeme und (bis pm
 * #10219) Favoriten — `GET /api/v1/users/me/apps`.
 *
 * Gefragt wird mit dem Dienst-Token des Produkts und der signierten
 * handelnden Person. AI Brain loest die Person dort auf; ein Produkt kann so
 * nicht nach der Leiste eines Fremden fragen.
 *
 * Eine Stelle fuer zwei Wege: die Leiste (angemeldete Person) und die
 * Uebernahme der Favoriten (jede verbundene Person, ausdruecklich genannt).
 */
class AiBrainLeiste
{
    /**
     * @param  string|null  $adresse  ausdruecklich genannte Person; ohne Angabe die handelnde
     * @return array{apps: list<array<string, mixed>>, pins: list<array<string, mixed>>}|null null, wenn AI Brain nicht mit Erfolg antwortet
     */
    public function abrufen(?string $adresse = null): ?array
    {
        $basis = rtrim((string) config('ai-brain-bridge.base_url'), '/');

        try {
            $antwort = Http::withToken(app(OAuthTokenProvider::class)->token())
                ->withHeaders(AiBrain::actingUserHeaders($adresse))
                ->acceptJson()
                ->timeout((int) config('ai-brain-bridge.switcher.timeout', 8))
                ->get($basis.'/api/v1/users/me/apps', [
                    'aktuell' => BridgeConfig::load()['source']
                        ?? config('ai-brain-bridge.source'),
                ]);

            if (! $antwort->successful()) {
                Log::warning('Umschaltleiste: AI Brain antwortete nicht mit Erfolg', [
                    'status' => $antwort->status(),
                ]);

                return null;
            }

            return [
                'apps' => is_array($antwort->json('apps')) ? array_values($antwort->json('apps')) : [],
                'pins' => is_array($antwort->json('pins')) ? array_values($antwort->json('pins')) : [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Umschaltleiste: AI Brain nicht erreichbar', ['fehler' => $e->getMessage()]);

            return null;
        }
    }
}
