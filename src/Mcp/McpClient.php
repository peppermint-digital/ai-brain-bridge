<?php

namespace Peppermint\AiBrainBridge\Mcp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Exceptions\AiBrainBridgeException;

/**
 * Minimaler MCP-Client (Streamable-HTTP, JSON-RPC) für einen MCP-Server —
 * z.B. AI Brain (/mcp/brain) oder ein Produkt (/mcp/{produkt}).
 *
 * Schiene 1 (synchrone Daten/Aktionen). Auth via OAuth-Bearer.
 *
 * Hinweis v0.1: Der Initialize-Handshake + Session-Header sind hier bewusst
 * schlank gehalten; in P2 gegen den echten laravel/mcp-HTTP-Transport
 * verifizieren (siehe Wiki „Kommunikationslayer v1").
 */
class McpClient
{
    protected ?string $sessionId = null;

    public function __construct(
        protected string $endpoint,
        protected OAuthTokenProvider $tokens,
        protected int $timeout = 30,
    ) {}

    /**
     * Ruft ein MCP-Tool auf und gibt das (geparste) Ergebnis zurück.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $name, array $arguments = []): array
    {
        $this->ensureInitialized();

        $result = $this->rpc('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);

        return $this->parseToolResult($result);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTools(): array
    {
        $this->ensureInitialized();

        return (array) ($this->rpc('tools/list')['tools'] ?? []);
    }

    protected function ensureInitialized(): void
    {
        if ($this->sessionId !== null) {
            return;
        }

        $response = $this->request()->post($this->endpoint, $this->envelope('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => ['tools' => (object) []],
            'clientInfo' => ['name' => 'peppermint/ai-brain-bridge', 'version' => '0.1.0'],
        ]));

        $this->sessionId = $response->header('Mcp-Session-Id') ?: null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function rpc(string $method, array $params = []): array
    {
        $response = $this->request()->post($this->endpoint, $this->envelope($method, $params));

        if (! $response->successful()) {
            throw new AiBrainBridgeException("MCP {$method} failed: HTTP {$response->status()}");
        }

        $body = $response->json();

        if (isset($body['error'])) {
            $msg = $body['error']['message'] ?? 'unknown error';
            throw new AiBrainBridgeException("MCP {$method} error: {$msg}");
        }

        return (array) ($body['result'] ?? []);
    }

    protected function request(): PendingRequest
    {
        $req = Http::timeout($this->timeout)
            ->withToken($this->tokens->token())
            ->acceptJson()
            ->withHeaders(['Content-Type' => 'application/json']);

        return $this->sessionId ? $req->withHeaders(['Mcp-Session-Id' => $this->sessionId]) : $req;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function envelope(string $method, array $params): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => bin2hex(random_bytes(8)),
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * MCP-Tool-Resultate sind ein content[]-Array; wir geben strukturiert
     * `structuredContent` zurück, sonst den zusammengefügten Text.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function parseToolResult(array $result): array
    {
        if (isset($result['structuredContent']) && is_array($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        $text = collect($result['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : ['text' => $text, 'isError' => (bool) ($result['isError'] ?? false)];
    }
}
