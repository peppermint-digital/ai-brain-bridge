<?php

return [
    /*
    | Basis-URL von AI Brain (der Hub).
    */
    'base_url' => env('AI_BRAIN_URL', 'https://brain.proxy.peppermint-digital.com'),

    /*
    | Dieses Produkt — Quelle in Events + Registry-Zuordnung.
    */
    'source' => env('AI_BRAIN_PRODUCT_SLUG', env('APP_NAME', 'unknown')),

    /*
    | OAuth2 client-credentials (Laravel Passport). Einheitliche Auth für
    | MCP- und Event-Calls an AI Brain. Scope mcp:use.
    */
    'oauth' => [
        'token_url' => env('AI_BRAIN_OAUTH_TOKEN_URL'),
        'client_id' => env('AI_BRAIN_CLIENT_ID'),
        'client_secret' => env('AI_BRAIN_CLIENT_SECRET'),
        'scope' => env('AI_BRAIN_SCOPE', 'mcp:use'),
        'cache_key' => 'ai_brain_bridge.oauth_token',
    ],

    /*
    | MCP — synchrone Daten/Aktionen (Schiene 1).
    */
    'mcp' => [
        'brain_url' => env('AI_BRAIN_MCP_URL'),
        'timeout' => (int) env('AI_BRAIN_MCP_TIMEOUT', 30),
    ],

    /*
    | Channels (Spezialfall MCP/REST): price-research, offer, …
    */
    'channels' => [
        'base' => env('AI_BRAIN_CHANNELS_URL'),
    ],

    /*
    | Events — asynchrone Benachrichtigungen (Schiene 2).
    */
    'events' => [
        'endpoint' => env('AI_BRAIN_EVENTS_URL'),
        'secret' => env('AI_BRAIN_EVENT_SECRET'),
        'timeout' => (int) env('AI_BRAIN_EVENT_TIMEOUT', 10),
        'retry' => [
            'times' => (int) env('AI_BRAIN_EVENT_RETRY', 3),
            'sleep_ms' => (int) env('AI_BRAIN_EVENT_RETRY_SLEEP', 250),
        ],
    ],

    /*
    | Inbound-Webhook: AI Brain → dieses Produkt. Signaturgeprüft, idempotent.
    */
    'inbound' => [
        'route' => env('AI_BRAIN_WEBHOOK_ROUTE', '/webhooks/ai-brain'),
        'middleware' => ['api'],
        'idempotency_ttl' => (int) env('AI_BRAIN_IDEMPOTENCY_TTL', 86400),
    ],
];
