<?php

namespace Peppermint\AiBrainBridge;

use Illuminate\Support\Facades\Event;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Channels\ChannelClient;
use Peppermint\AiBrainBridge\Events\AiBrainEventReceived;
use Peppermint\AiBrainBridge\Events\Event as BridgeEvent;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Mcp\McpClient;

/**
 * Zentrale Fassade des Bridge-SDK. Kapselt beide Schienen:
 *  - MCP (synchron): call(), brain(), mcp()
 *  - Channels (Spezial-MCP): channel()
 *  - Events (async): emit(), on()
 *
 * Jedes Produkt nutzt nur diese API — kein rohes JSON-RPC/HTTP/Auth/Retry.
 */
class AiBrainManager
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected OAuthTokenProvider $tokens,
        protected EventPublisher $publisher,
    ) {}

    // ── MCP (Schiene 1) ──────────────────────────────────────────────────

    /**
     * Ruft ein AI-Brain-MCP-Tool auf (z.B. create-task-tool, list-projects-tool).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $tool, array $arguments = []): array
    {
        return $this->brain()->callTool($tool, $arguments);
    }

    public function brain(): McpClient
    {
        $url = $this->config['mcp']['brain_url']
            ?: rtrim((string) $this->config['base_url'], '/').'/mcp/brain';

        return new McpClient($url, $this->tokens, (int) ($this->config['mcp']['timeout'] ?? 30));
    }

    /**
     * MCP-Client für eine beliebige URL (z.B. ein Produkt-MCP-Server).
     */
    public function mcp(string $url): McpClient
    {
        return new McpClient($url, $this->tokens, (int) ($this->config['mcp']['timeout'] ?? 30));
    }

    // ── Channels ─────────────────────────────────────────────────────────

    public function channel(string $channel): ChannelClient
    {
        $base = $this->config['channels']['base'] ?: rtrim((string) $this->config['base_url'], '/');

        return new ChannelClient($base, $channel, $this->tokens);
    }

    // ── Events (Schiene 2) ───────────────────────────────────────────────

    /**
     * Event an AI Brain publizieren (signiert, idempotent, retried).
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $type, array $payload, ?string $entityRef = null, ?string $correlationId = null): bool
    {
        return $this->publisher->publish(new BridgeEvent(
            type: $type,
            payload: $payload,
            source: (string) $this->config['source'],
            entityRef: $entityRef,
            correlationId: $correlationId,
        ));
    }

    /**
     * Komfort: auf einen bestimmten eingehenden Event-Typ reagieren.
     * (Alternativ ganz normal auf AiBrainEventReceived lauschen.)
     */
    public function on(string $type, callable $handler): void
    {
        Event::listen(AiBrainEventReceived::class, function (AiBrainEventReceived $e) use ($type, $handler): void {
            if ($e->event->type === $type) {
                $handler($e->event);
            }
        });
    }
}
