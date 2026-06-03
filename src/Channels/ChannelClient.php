<?php

namespace Peppermint\AiBrainBridge\Channels;

use Peppermint\AiBrainBridge\Mcp\McpClient;

/**
 * Client für einen AI-Brain-Channel (price-research, offer-extraction, …).
 *
 * „Ein Channel = ein Chat" (wie Telegram/Avatar). Läuft über den einheitlichen
 * MCP-Weg (/mcp/brain, OAuth mcp:use) — dieselbe Schiene wie alle anderen Tools:
 *   AiBrain::channel('price-research')->message(['product' => 'Poloshirt']);
 *   AiBrain::channel('price-research')->thread($chatId);
 *   AiBrain::channel('price-research')->reply($chatId, 'Der blaue');
 *
 * Der Agent schreibt Ergebnisse direkt per Produkt-MCP zurück; Rückfragen kommen
 * als channel.reply-Event oder via thread()-Polling (status=needs_input).
 */
class ChannelClient
{
    public function __construct(
        protected McpClient $brain,
        protected string $channel,
    ) {}

    /**
     * Anfrage in den Channel-Chat schicken. Gib strukturierte $payload und/oder
     * einen Freitext $text an.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>  { ok, chat_id, channel, status }
     */
    public function message(array $payload = [], ?string $text = null, ?string $ref = null): array
    {
        return $this->brain->callTool('channel-message-tool', array_filter([
            'channel' => $this->channel,
            'payload' => $payload !== [] ? $payload : null,
            'message' => $text,
            'external_ref' => $ref,
        ], fn ($v) => $v !== null));
    }

    /**
     * Status + kompletten Thread eines Channel-Chats (zum Pollen).
     *
     * @return array<string, mixed>  { ok, status, messages: [...] }
     */
    public function thread(int $chatId): array
    {
        return $this->brain->callTool('channel-thread-tool', ['chat_id' => $chatId]);
    }

    /**
     * Im Channel-Chat antworten (z.B. auf eine needs_input-Rückfrage).
     *
     * @return array<string, mixed>  { ok, chat_id, message_id, status }
     */
    public function reply(int $chatId, string $content): array
    {
        return $this->brain->callTool('channel-reply-tool', [
            'chat_id' => $chatId,
            'content' => $content,
        ]);
    }

    // ── Deprecated Aliase (alte invoke/messages-Namen) ───────────────────────

    /**
     * @deprecated Nutze message(). Bleibt für Bestandscode.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function invoke(array $payload, ?string $ref = null): array
    {
        return $this->message($payload, ref: $ref);
    }

    /**
     * @deprecated Nutze thread().
     *
     * @return array<string, mixed>
     */
    public function messages(int $chatId): array
    {
        return $this->thread($chatId);
    }
}
