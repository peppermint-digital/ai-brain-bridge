<?php

namespace Peppermint\AiBrainBridge\Health;

use Peppermint\AiBrainBridge\Health\ExceptionRecorder;
use Peppermint\AiBrainBridge\Health\SlowQueryRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Sammelt den App-Health-Snapshot RECHTELOS aus der App selbst: ältester
 * unbearbeiteter Queue-Job (database-Queue), Queue-Größe, failed_jobs, DB-Ping,
 * Uptime. Alles fail-safe — fehlt eine Tabelle/DB, wird das Feld null.
 */
class HealthCollector
{
    /**
     * @return array<string, mixed>
     */
    public function collect(bool $flush = true): array
    {
        $latency = null;
        $dbOk = $this->databaseOk($latency);

        return [
            'app_name' => $this->appName(),
            'project' => config('ai-brain-bridge.health.project') ?: null,
            'hostname' => gethostname() ?: null,
            'queue_oldest_pending_seconds' => $this->queueOldestPendingSeconds(),
            'queue_size' => $this->queueSize(),
            'failed_jobs' => $this->failedJobs(),
            'db_ok' => $dbOk,
            'db_latency_ms' => $latency,
            'uptime_seconds' => $this->uptimeSeconds(),
            'app_version' => $this->appVersion(),
            'php_version' => PHP_VERSION,
        ] + $this->recorderMetrics($flush);
    }

    /**
     * Fehler + langsame Queries als JSON-String (überlebt jede Transport-
     * Serialisierung unverändert).
     *
     * Wichtig: Ist ein Recorder aktiv, wird auch bei LEEREM Fenster gesendet
     * (`[]`). Sonst fehlte das Feld, der Server könnte „keine Fehler mehr" nicht
     * von „meldet das Feature nicht" unterscheiden — und ein offener Alert würde
     * sich nie wieder schließen.
     *
     * @return array<string, mixed>
     */
    protected function recorderMetrics(bool $flush): array
    {
        $out = [];

        if (config('ai-brain-bridge.health.exceptions.enabled', true)) {
            $out['exceptions_json'] = $this->encodeList(app(ExceptionRecorder::class)->pull($flush));
        }

        if (config('ai-brain-bridge.health.slow_queries.enabled', true)) {
            $slow = app(SlowQueryRecorder::class);
            $out['slow_queries_json'] = $this->encodeList($slow->pull($flush));
            $out['slow_query_threshold_ms'] = $slow->thresholdMs();
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function encodeList(array $items): string
    {
        $json = json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '[]';
    }

    /**
     * App-Name für AI Brain. Zero-Config-Reihenfolge:
     *   1. explizit gesetzt (AI_BRAIN_CONNECTOR_APP)
     *   2. Produkt-Slug der Bridge-Anbindung (`ai-brain-bridge.source`) — eindeutig
     *      und identisch mit dem Projekt-Slug in AI Brain
     *   3. APP_NAME als letzter Fallback
     *
     * Punkt 2 ist wichtig: APP_NAME ist in vielen Apps noch der Default „Laravel",
     * und der App-Slug ist in AI Brain global eindeutig — zwei „Laravel"-Apps
     * würden sich sonst gegenseitig überschreiben.
     */
    protected function appName(): string
    {
        $configured = config('ai-brain-bridge.health.app_name');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured); // explizit gesetzt → verbatim, kein Suffix
        }

        $source = config('ai-brain-bridge.source');
        $base = is_string($source) && trim($source) !== ''
            ? trim($source)
            : (string) config('app.name', 'app');

        // Ein Produkt(-Slug) kann mehrere Deployments haben (staging/prod), evtl.
        // auf DEMSELBEN Host — Hostname unterscheidet sie dann nicht. Ohne Suffix
        // würden sie sich im global-eindeutigen app_healths.slug gegenseitig
        // überschreiben. Nicht-Production-Umgebungen daher kennzeichnen — ABER
        // nur, wenn der Name die Umgebung nicht ohnehin schon enthält (sonst
        // entstünde „…-staging (staging)", wenn der Produkt-Slug bereits -staging
        // trägt).
        $env = (string) app()->environment();
        if ($env !== '' && $env !== 'production' && ! str_contains(strtolower($base), strtolower($env))) {
            return $base.' ('.$env.')';
        }

        return $base;
    }

    /**
     * Alter (Sekunden) des ältesten unbearbeiteten Jobs in der `jobs`-Tabelle
     * (database-Queue). Nur verfügbare (available_at ≤ jetzt), noch nicht
     * reservierte Jobs zählen → fängt „Worker läuft, verarbeitet aber nichts".
     */
    protected function queueOldestPendingSeconds(): ?int
    {
        try {
            if (! Schema::hasTable('jobs')) {
                return null;
            }

            $now = time();
            $oldest = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->min('created_at');

            if ($oldest === null) {
                return 0; // keine wartenden Jobs = kein Rückstand
            }

            return max(0, $now - (int) $oldest);
        } catch (Throwable) {
            return null;
        }
    }

    protected function queueSize(): ?int
    {
        try {
            return Schema::hasTable('jobs') ? (int) DB::table('jobs')->whereNull('reserved_at')->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function failedJobs(): ?int
    {
        try {
            return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function databaseOk(?int &$latencyMs): bool
    {
        try {
            $start = microtime(true);
            DB::select('select 1');
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            return true;
        } catch (Throwable) {
            $latencyMs = null;

            return false;
        }
    }

    protected function uptimeSeconds(): ?int
    {
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false) {
            return null;
        }
        $parts = explode(' ', trim($raw));

        return isset($parts[0]) && is_numeric($parts[0]) ? (int) round((float) $parts[0]) : null;
    }

    protected function appVersion(): ?string
    {
        return env('APP_VERSION') ?: null;
    }
}
