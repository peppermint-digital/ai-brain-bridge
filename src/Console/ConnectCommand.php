<?php

namespace Peppermint\AiBrainBridge\Console;

use Illuminate\Console\Command;
use Peppermint\AiBrainBridge\Config\BridgeConfig;
use Peppermint\AiBrainBridge\Connect\Connector;

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

    public function handle(Connector $connector): int
    {
        $result = $connector->connect(
            (string) $this->argument('code'),
            $this->option('url') ? (string) $this->option('url') : null,
        );

        if (! $result['ok']) {
            $this->error("Anbindung fehlgeschlagen: {$result['error']}");

            return self::FAILURE;
        }

        $this->info("Angebunden als {$result['product_slug']}. Config gespeichert: ".BridgeConfig::path());
        $this->line('Verifizieren mit:  php artisan ai-brain:selftest');

        return self::SUCCESS;
    }
}
