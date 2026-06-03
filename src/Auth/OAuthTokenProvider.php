<?php

namespace Peppermint\AiBrainBridge\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Exceptions\AiBrainBridgeException;

/**
 * Holt + cached ein OAuth2-client-credentials-Access-Token (Scope mcp:use)
 * für die Calls an AI Brain. Einheitliche Auth für MCP + Events.
 */
class OAuthTokenProvider
{
    /**
     * @param  array<string, mixed>  $config  Der 'oauth'-Block + base_url.
     */
    public function __construct(protected array $config) {}

    public function token(): string
    {
        $cacheKey = $this->config['cache_key'] ?? 'ai_brain_bridge.oauth_token';

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenUrl = $this->config['token_url']
            ?: rtrim((string) ($this->config['base_url'] ?? ''), '/').'/oauth/token';

        $response = Http::asForm()->timeout(10)->post($tokenUrl, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config['client_id'] ?? '',
            'client_secret' => $this->config['client_secret'] ?? '',
            'scope' => $this->config['scope'] ?? 'mcp:use',
        ]);

        if (! $response->successful()) {
            throw new AiBrainBridgeException("OAuth token request failed: HTTP {$response->status()}");
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        if ($token === '') {
            throw new AiBrainBridgeException('OAuth response missing access_token.');
        }

        // 60s Sicherheitsabstand vor Ablauf.
        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }

    public function forget(): void
    {
        Cache::forget($this->config['cache_key'] ?? 'ai_brain_bridge.oauth_token');
    }
}
