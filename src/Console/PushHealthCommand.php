<?php

namespace Peppermint\AiBrainBridge\Console;

use Illuminate\Console\Command;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Health\HealthCollector;
use Throwable;

/**
 * Sammelt den App-Health-Snapshot und meldet ihn über den vorhandenen
 * MCP-Weg (OAuth `mcp:use`) an AI Brains `report-app-health-tool`.
 *
 * Kam aus dem eigenständigen Paket `ai-brain/laravel-connector` und wohnt seit
 * K7 (#5239) hier: Ein Produkt, das sich anbindet, soll ein Paket installieren
 * und einen Konfigurationsblock pflegen, nicht zwei mit derselben Gegenstelle.
 */
class PushHealthCommand extends Command
{
    protected $signature = 'ai-brain:push-health {--dry-run : Nur den Payload ausgeben, nicht senden}';

    protected $description = 'Sammelt App-Health-Metriken und meldet sie an AI Brain.';

    public function handle(HealthCollector $collector): int
    {
        if (! config('ai-brain-bridge.health.enabled')) {
            $this->info('ai-brain: Health-Meldung ist abgeschaltet.');

            return self::SUCCESS;
        }

        // Nur nicht-null-Werte senden (false/0 bleiben erhalten — z.B. db_ok=false).
        $metrics = array_filter($collector->collect(flush: ! $this->option('dry-run')), fn ($v) => $v !== null);

        if ($this->option('dry-run')) {
            $this->line((string) json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        try {
            AiBrain::call('report-app-health-tool', $metrics);
            $this->info("ai-brain: Health gemeldet ({$metrics['app_name']}).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('ai-brain: Health-Meldung fehlgeschlagen — '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
