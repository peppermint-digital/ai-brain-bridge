<?php

namespace Peppermint\AiBrainBridge\Health;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sammelt Fehler der App zwischen zwei Health-Pushes — Telescope-artig, aber
 * bewusst aggregiert statt vollständig: pro Fehler-Fingerprint nur Klasse, Ort
 * und Häufigkeit. Keine Stacktraces, keine Payloads, kein Datenabfluss.
 *
 * Angebunden wird `MessageLogged` statt eines Exception-Handlers: Laravel logt
 * unbehandelte Exceptions über den Logger, das Event feuert also ohnehin — und
 * die App muss ihre `bootstrap/app.php` nicht anfassen (Zero-Config).
 *
 * Ablage im Cache, nicht in einer Tabelle: das Package soll keine Migration in
 * fremde Apps tragen. Ist der Cache-Store `array` (kein Shared State zwischen
 * Requests), bleibt die Erfassung wirkungslos — das ist dokumentiert und
 * degradiert still, statt etwas kaputt zu machen.
 */
class ExceptionRecorder
{
    public const CACHE_KEY = 'ai_brain_connector:exceptions';

    /** @var array<int, string> Log-Level, die als Fehler zählen. */
    protected const LEVELS = ['error', 'critical', 'alert', 'emergency'];

    /**
     * Rekursionsschutz: schlägt der Cache-Schreibvorgang fehl und wird das
     * geloggt, feuert MessageLogged erneut — ohne Sperre endlos.
     */
    protected bool $recording = false;

    public function record(MessageLogged $event): void
    {
        if ($this->recording) {
            return;
        }

        try {
            $this->recording = true;
            if (! in_array($event->level, self::LEVELS, true)) {
                return;
            }

            $exception = $event->context['exception'] ?? null;
            $entry = $exception instanceof Throwable
                ? $this->fromThrowable($exception)
                : $this->fromMessage((string) $event->message);

            $this->store($entry);
        } catch (Throwable) {
            // Monitoring darf die App nie stören.
        } finally {
            $this->recording = false;
        }
    }

    /**
     * Gesammelte Fehler holen und das Fenster leeren, damit der nächste Push
     * nur Neues meldet. Fenster = Zeitraum zwischen zwei Pushes.
     * `$flush = false` für --dry-run: schauen, ohne das Fenster zu verbrauchen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pull(bool $flush = true): array
    {
        try {
            $items = Cache::get(self::CACHE_KEY, []);
            if ($flush) {
                Cache::forget(self::CACHE_KEY);
            }

            if (! is_array($items) || $items === []) {
                return [];
            }

            usort($items, fn (array $a, array $b): int => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

            return array_slice(array_values($items), 0, $this->maxItems());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromThrowable(Throwable $e): array
    {
        return [
            'class' => $e::class,
            'message' => $this->truncate($e->getMessage()),
            'file' => $this->relativePath($e->getFile()),
            'line' => $e->getLine(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromMessage(string $message): array
    {
        return [
            'class' => 'log.error',
            'message' => $this->truncate($message),
            'file' => null,
            'line' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function store(array $entry): void
    {
        $fingerprint = sha1(($entry['class'] ?? '').'|'.($entry['file'] ?? '').'|'.($entry['line'] ?? '').'|'.($entry['message'] ?? ''));

        $items = Cache::get(self::CACHE_KEY, []);
        if (! is_array($items)) {
            $items = [];
        }

        // Obergrenze gegen unbegrenztes Wachstum bei einem Fehler-Sturm mit
        // vielen unterschiedlichen Fingerprints.
        if (! isset($items[$fingerprint]) && count($items) >= $this->maxItems() * 4) {
            return;
        }

        if (isset($items[$fingerprint])) {
            $items[$fingerprint]['count']++;
        } else {
            $items[$fingerprint] = $entry + ['count' => 1];
        }

        Cache::put(self::CACHE_KEY, $items, now()->addHours(6));
    }

    protected function maxItems(): int
    {
        return max(1, (int) config('ai-brain-bridge.health.exceptions.max_items', 10));
    }

    protected function truncate(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > 200 ? mb_substr($value, 0, 200).'…' : $value;
    }

    /**
     * Pfade relativ zur App-Basis — kürzer und ohne Server-Verzeichnisstruktur.
     */
    protected function relativePath(string $path): string
    {
        $base = base_path();

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : $path;
    }
}
