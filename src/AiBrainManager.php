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
     * Acting-User-Resolver (Connector Phase 4.1): liefert die E-Mail des aktuell
     * eingeloggten Produkt-Users oder null. Default aus der Config, zur Laufzeit
     * via resolveActingUserUsing() überschreibbar.
     *
     * @var (callable(): ?string)|null
     */
    protected $actingUserResolver;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected OAuthTokenProvider $tokens,
        protected EventPublisher $publisher,
    ) {
        $resolver = $config['acting_user']['resolver'] ?? null;
        $this->actingUserResolver = is_callable($resolver) ? $resolver : null;
    }

    // ── MCP (Schiene 1) ──────────────────────────────────────────────────

    /**
     * Setzt den Acting-User-Resolver zur Laufzeit (Alternative zur Config).
     * Das Produkt darf NUR den authentifizierten User behaupten:
     * AiBrain::resolveActingUserUsing(fn () => auth()->user()?->email);
     *
     * @param  (callable(): ?string)|null  $resolver
     */
    public function resolveActingUserUsing(?callable $resolver): self
    {
        $this->actingUserResolver = $resolver;

        return $this;
    }

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

        return new McpClient($url, $this->tokens, (int) ($this->config['mcp']['timeout'] ?? 30), $this->actingUserResolver);
    }

    /**
     * MCP-Client für eine beliebige URL (z.B. ein Produkt-MCP-Server).
     */
    public function mcp(string $url): McpClient
    {
        return new McpClient($url, $this->tokens, (int) ($this->config['mcp']['timeout'] ?? 30), $this->actingUserResolver);
    }

    // ── Channels (Spezial-MCP) ───────────────────────────────────────────

    /**
     * Channel-Client für einen Channel. Läuft über den einheitlichen MCP-Weg
     * (/mcp/brain): AiBrain::channel('price-research')->message([...]).
     */
    public function channel(string $channel): ChannelClient
    {
        return new ChannelClient($this->brain(), $channel);
    }

    /**
     * Verfügbare (invokable) Channels — Discovery via channel-list-tool.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return (array) ($this->call('channel-list-tool')['channels'] ?? []);
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
