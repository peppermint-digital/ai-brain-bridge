<?php

namespace Peppermint\AiBrainBridge\Events;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;

/**
 * Publiziert Events an AI Brain (Schiene 2). Signiert (HMAC), idempotent,
 * mit Retry/Backoff. Fail-safe: ein fehlgeschlagenes Event wirft nicht in
 * den Produkt-Flow, sondern wird geloggt (Retry später / via Outbox).
 */
class EventPublisher
{
    /**
     * @param  array<string, mixed>  $config  Der 'events'-Block.
     */
    public function __construct(
        protected array $config,
        protected OAuthTokenProvider $tokens,
        protected string $source,
        protected string $baseUrl = '',
    ) {}

    public function publish(Event $event): bool
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '')
            ?: rtrim($this->baseUrl, '/').'/api/v1/events';
        $secret = (string) ($this->config['secret'] ?? '');

        if ($endpoint === '') {
            Log::warning('ai-brain-bridge: events.endpoint not configured', ['type' => $event->type]);

            return false;
        }

        $rawBody = json_encode($event->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $retry = $this->config['retry'] ?? ['times' => 3, 'sleep_ms' => 250];

        try {
            $response = Http::withToken($this->tokens->token())
                ->timeout((int) ($this->config['timeout'] ?? 10))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    EventSignature::HEADER => EventSignature::sign($rawBody, $secret),
                    'X-Idempotency-Key' => $event->idempotencyKey,
                ])
                ->retry((int) $retry['times'], (int) $retry['sleep_ms'])
                ->withBody($rawBody, 'application/json')
                ->post($endpoint);

            if ($response->successful()) {
                return true;
            }

            Log::warning('ai-brain-bridge: event publish non-success', [
                'type' => $event->type,
                'status' => $response->status(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('ai-brain-bridge: event publish failed', [
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
