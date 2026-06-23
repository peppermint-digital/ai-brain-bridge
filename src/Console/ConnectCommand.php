<?php

namespace Peppermint\AiBrainBridge\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Config\BridgeConfig;

/**
 * One-Click-Anbindung an AI Brain (Connector-Plattform Phase 1, Spec #249).
 *
 * Das Produkt löst EINMALIG einen im Hub ausgestellten Claim-Code ein und erhält
 * sein Config-Bundle (client_id/secret, event_secret, urls), das im Settings-
 * Store landet. Danach ist die Anbindung aktiv — ohne `.env`-Editieren.
 *
 *   php artisan ai-brain:connect <claim-code> --url=https://brain.proxy.peppermint-digital.com
 */
class ConnectCommand extends Command
{
    protected $signature = 'ai-brain:connect
                            {code : Claim-Code aus AI Brain (Connected Products → „Anbindungs-Code ausstellen")}
                            {--url= : Brain-URL (Default: ai-brain-bridge.base_url)}';

    protected $description = 'Bindet dieses Produkt per Claim-Code an AI Brain an (holt Client-Credentials + Event-Secret).';

    public function handle(): int
    {
        $code = trim((string) $this->argument('code'));
        $base = rtrim((string) ($this->option('url') ?: config('ai-brain-bridge.base_url')), '/');

        if ($base === '') {
            $this->error('Keine Brain-URL — per --url angeben oder AI_BRAIN_URL setzen.');

            return self::FAILURE;
        }

        $res = Http::acceptJson()->timeout(15)->post("{$base}/api/v1/connect/claim", ['code' => $code]);

        if (! $res->successful()) {
            $msg = $res->json('message') ?? "HTTP {$res->status()}";
            $this->error("Anbindung fehlgeschlagen: {$msg}");

            return self::FAILURE;
        }

        $b = (array) $res->json();

        BridgeConfig::save([
            'base_url' => $b['brain_url'] ?? $base,
            'source' => $b['product_slug'] ?? config('ai-brain-bridge.source'),
            'oauth' => [
                'client_id' => $b['client_id'] ?? null,
                'client_secret' => $b['client_secret'] ?? null,
                'scope' => $b['scope'] ?? 'mcp:use',
            ],
            'mcp' => ['brain_url' => $b['brain_mcp_url'] ?? null],
            'events' => [
                'endpoint' => $b['events_url'] ?? null,
                'secret' => $b['event_secret'] ?? null,
            ],
            'jwt_public_key_url' => $b['jwt_public_key_url'] ?? null,
        ]);

        // Sofort wirksam im laufenden Prozess (z.B. für einen direkt folgenden Selbsttest).
        BridgeConfig::apply();

        $slug = $b['product_slug'] ?? 'unbekannt';
        $this->info("Angebunden als {$slug}. Config gespeichert: ".BridgeConfig::path());
        $this->line('Verifizieren mit:  php artisan ai-brain:selftest');

        return self::SUCCESS;
    }
}
