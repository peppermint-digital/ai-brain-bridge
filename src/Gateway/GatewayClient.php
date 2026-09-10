<?php

namespace Peppermint\AiBrainBridge\Gateway;

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;

/**
 * Einen Vorgang bei einem ANDEREN System auslösen — über AI Brain (#5243).
 *
 * ## Was das ersetzt
 *
 * Die Peer-Schiene: bisher redete jedes Produkt direkt mit jedem anderen, jede
 * Verbindung mit eigenem Geheimnis, eigener Rechteprüfung und eigenem (oder
 * keinem) Protokoll. Wer eine Gegenstelle dazunahm, baute die Verbindung neu.
 *
 * ## Der Unterschied im Aufruf
 *
 * Man nennt einen VORGANG, kein Zielsystem:
 *
 *     AiBrain::gateway('tasks.create', ['title' => 'Ticket 5']);
 *
 * Welches System das bedient, weiß das Fähigkeits-Register in Brain — gemessen
 * an dem, was die Systeme tatsächlich anbieten. Die Verwaltung muss also nicht
 * wissen, wo Aufgaben liegen; sie muss nur wissen, dass sie welche braucht.
 * Zieht der Vorgang eines Tages in ein anderes System um, ändert sich hier
 * nichts.
 *
 * ## Fehler sind unterscheidbar
 *
 * `not_offered` (das kann gerade niemand), `not_allowed` (dieses System darf
 * das nicht), `unreachable` (das Ziel antwortet nicht), `tool_failed` (das Ziel
 * hat abgelehnt). Ein Aufrufer soll wissen, ob er es später noch einmal
 * versuchen soll — ohne Zeichenketten zu raten.
 */
class GatewayClient
{
    /**
     * @param  array<string, mixed>  $config
     * @param  callable(): array<string, string>  $actingHeaders
     */
    public function __construct(
        protected array $config,
        protected OAuthTokenProvider $tokens,
        protected $actingHeaders,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  string|null  $product  Nur angeben, wenn ausdrücklich EIN System gemeint ist.
     * @return array{ok: bool, data: array<mixed>|null, text: string|null, error: string|null, message: string|null, product: string|null}
     */
    public function call(string $capability, array $arguments = [], ?string $product = null): array
    {
        $url = rtrim((string) ($this->config['base_url'] ?? ''), '/').'/api/v1/gateway';

        try {
            $antwort = Http::withToken($this->tokens->token())
                ->withHeaders(($this->actingHeaders)())
                ->acceptJson()
                ->timeout((int) ($this->config['gateway']['timeout'] ?? 20))
                ->post($url, array_filter([
                    'capability' => $capability,
                    'arguments' => $arguments,
                    'product' => $product,
                ], fn ($wert) => $wert !== null));
        } catch (\Throwable $e) {
            // Auch der Weg ZU Brain kann ausfallen. Das ist derselbe Fall wie
            // ein nicht erreichbares Zielsystem — und muss sich für den
            // Aufrufer auch so anfühlen, statt als Ausnahme hochzuschlagen.
            return $this->fehler('unreachable', 'AI Brain nicht erreichbar: '.$e->getMessage());
        }

        $daten = $antwort->json();

        if (! is_array($daten)) {
            return $this->fehler('tool_failed', 'Unverständliche Antwort von AI Brain (HTTP '.$antwort->status().')');
        }

        return [
            'ok' => (bool) ($daten['ok'] ?? false),
            'data' => is_array($daten['data'] ?? null) ? $daten['data'] : null,
            'text' => $daten['text'] ?? null,
            'error' => $daten['error'] ?? null,
            'message' => $daten['message'] ?? null,
            'product' => $daten['product'] ?? null,
        ];
    }

    /**
     * @return array{ok: bool, data: null, text: null, error: string, message: string, product: null}
     */
    protected function fehler(string $klasse, string $meldung): array
    {
        return [
            'ok' => false,
            'data' => null,
            'text' => null,
            'error' => $klasse,
            'message' => $meldung,
            'product' => null,
        ];
    }
}
