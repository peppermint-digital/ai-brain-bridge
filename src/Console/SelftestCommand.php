<?php

namespace Peppermint\AiBrainBridge\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Funktionstest der AI-Brain-Anbindung — aus Produktsicht (#2127).
 *
 * Jedes Produkt mit dem SDK bekommt `php artisan ai-brain:selftest`: ein
 * deterministischer grün/rot-Check mit Exit-Code (0 = alles grün) für
 * CI / Post-Deploy. Prüft die Schienen über den einheitlichen OAuth-Komm-Layer:
 *
 *  - oauth   : client_credentials-Token (mcp:use) mintbar
 *  - mcp     : AiBrain::call('list-projects-tool') erreichbar (Schiene 1)
 *  - events  : AiBrain::emit('selftest.ping') signiert zugestellt (Schiene 1, async)
 *  - channel : voller Loop über den `selftest`-Channel — Sentinel → Agent-Echo →
 *              thread() bis done → verifiziert (Schiene Channel + R2)
 */
class SelftestCommand extends Command
{
    protected $signature = 'ai-brain:selftest {--rail=all : oauth|mcp|events|channel|all} {--timeout=60 : Sekunden für den Channel-Loop}';

    protected $description = 'Funktionstest der AI-Brain-Anbindung (OAuth/MCP/Events/Channel) — grün/rot + Exit-Code';

    public function handle(OAuthTokenProvider $tokens): int
    {
        if (empty(config('ai-brain-bridge.oauth.client_id'))) {
            $this->error('AI Brain Bridge nicht konfiguriert (ai-brain-bridge.oauth.client_id fehlt).');

            return self::FAILURE;
        }

        $rail = (string) $this->option('rail');
        $all = $rail === 'all';

        /** @var array<string, bool> $results */
        $results = [];

        if ($all || $rail === 'oauth') {
            $results['oauth'] = $this->check(fn (): bool => $tokens->token() !== '');
        }

        if ($all || $rail === 'mcp') {
            $results['mcp:list-projects-tool'] = $this->check(function (): bool {
                $r = AiBrain::call('list-projects-tool');

                return is_array($r) && empty($r['error']);
            });
        }

        if ($all || $rail === 'events') {
            $results['events:emit'] = $this->check(
                fn (): bool => AiBrain::emit('selftest.ping', ['ping' => true]) === true,
            );
        }

        if ($all || $rail === 'channel') {
            $results['channel:selftest'] = $this->check(
                fn (): bool => $this->channelLoop(max(10, (int) $this->option('timeout'))),
            );
        }

        if ($results === []) {
            $this->error("Unbekannte Schiene: {$rail}. Erlaubt: oauth|mcp|events|channel|all.");

            return self::INVALID;
        }

        $ok = true;
        foreach ($results as $label => $passed) {
            $this->line(($passed ? '<fg=green>  ✓</>' : '<fg=red>  ✗</>')." {$label}");
            $ok = $ok && $passed;
        }

        if ($ok) {
            $this->info('AI-Brain-Selftest: ALLES GRÜN ✓');

            return self::SUCCESS;
        }

        $this->error('AI-Brain-Selftest: FEHLER — mindestens ein Check ist rot.');

        return self::FAILURE;
    }

    /** Fängt jede Exception → roter Check statt Crash. */
    protected function check(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Voller Channel-Round-Trip über den `selftest`-Channel: Sentinel rein, der
     * Agent echo't ihn → thread() bis done → in result verifizieren.
     */
    protected function channelLoop(int $timeout): bool
    {
        $sentinel = 'SELFTEST-'.Str::upper(Str::random(8));

        $r = AiBrain::channel('selftest')->message(['ping' => $sentinel]);
        $chatId = $r['chat_id'] ?? null;
        if (! is_numeric($chatId)) {
            return false;
        }

        $deadline = time() + $timeout;
        do {
            $t = AiBrain::channel('selftest')->thread((int) $chatId);
            $status = (string) ($t['status'] ?? '');

            if ($status === 'done') {
                $result = $t['result'] ?? '';

                return is_string($result) && str_contains($result, $sentinel);
            }
            if ($status === 'failed') {
                return false;
            }
            if (time() >= $deadline) {
                return false;
            }

            sleep(2);
        } while (true);
    }
}
