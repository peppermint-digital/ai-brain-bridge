<?php

namespace Peppermint\AiBrainBridge\Channels;

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Exceptions\AiBrainBridgeException;

/**
 * Client für einen AI-Brain-Channel (price-research, offer, …).
 *
 * „Ein Channel = ein Chat": invoke() postet die Anfrage in den Channel-Chat,
 * messages()/reply() lesen/schreiben den Thread. Der Agent schreibt Ergebnisse
 * direkt per Produkt-MCP zurück.
 */
class ChannelClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $channel,
        protected OAuthTokenProvider $tokens,
    ) {}

    /**
     * Anfrage in den Channel-Chat schicken.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>  { ok, invocation_id, channel, status }
     */
    public function invoke(array $payload, ?string $ref = null): array
    {
        $response = $this->http()->post("{$this->baseUrl}/api/v1/channels/{$this->channel}/invoke", array_filter([
            'payload' => $payload,
            'external_ref' => $ref,
        ], fn ($v) => $v !== null));

        return $this->json($response, 'invoke');
    }

    /**
     * Den Chat-Thread (zum Anzeigen / Pollen).
     *
     * @return array<string, mixed>  { ok, status, messages: [...] }
     */
    public function messages(int $invocationId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/api/v1/channels/invocations/{$invocationId}/messages");

        return $this->json($response, 'messages');
    }

    /**
     * Im Channel-Chat antworten (z.B. auf eine needs_input-Rückfrage).
     *
     * @return array<string, mixed>
     */
    public function reply(int $invocationId, string $content): array
    {
        $response = $this->http()->post("{$this->baseUrl}/api/v1/channels/invocations/{$invocationId}/messages", [
            'content' => $content,
        ]);

        return $this->json($response, 'reply');
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->tokens->token())->acceptJson()->timeout(15);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(\Illuminate\Http\Client\Response $response, string $op): array
    {
        if ($response->status() >= 500 || $response->status() === 401) {
            throw new AiBrainBridgeException("Channel {$op} failed: HTTP {$response->status()}");
        }

        return (array) $response->json();
    }
}
