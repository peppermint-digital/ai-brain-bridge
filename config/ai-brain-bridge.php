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
    | Settings-Store der One-Click-Anbindung (`ai-brain:connect`). Das per Claim-
    | Code bezogene Bundle landet hier und überlagert beim Boot die ENV-Defaults.
    */
    'store_path' => env('AI_BRAIN_STORE_PATH', storage_path('app/ai-brain-bridge.json')),

    /*
    | Frontend-agnostische Connect-Route (Phase 2). Standard AUS — das Produkt
    | schaltet sie frei und MUSS eine Admin-Middleware setzen (schreibt Credentials).
    */
    'connect' => [
        'enabled' => (bool) env('AI_BRAIN_CONNECT_UI', false),
        'route' => env('AI_BRAIN_CONNECT_ROUTE', '/ai-brain/connect'),
        'middleware' => ['web'],
    ],

    /*
    | Peer-to-Peer-Konnektoren (Phase 3, Track B) — Produkt↔Produkt OHNE Brain.
    | Standard AUS. Wenn aktiv, registriert das SDK den öffentlichen claim-Endpoint
    | (Code = Secret, gethrottelt) + die admin-gegateten Verwaltungs-Routen.
    | `api_url` = wohin Peers MICH rufen (default: app.url).
    */
    'peer' => [
        // Standardmäßig AN — die Peer-Anbindung läuft komplett über die Produkt-UI,
        // ohne .env-Eingriff. Der claim-Endpoint ist ohne ausgestellte Codes nur eine
        // 422-Maschine (Token = Secret, einmalig, 15 Min). Opt-out via PEER_CONNECT=false.
        'enabled' => filter_var(env('PEER_CONNECT', true), FILTER_VALIDATE_BOOL),
        // Eigene API-Basis (wohin Peers MICH rufen). Default: app.url — kein .env nötig.
        'api_url' => env('PEER_API_URL'),
        'openapi_url' => env('PEER_OPENAPI_URL'),
        'claim_route' => env('PEER_CLAIM_ROUTE', '/api/v1/connect/claim'),
        'claim_middleware' => ['api', 'throttle:20,1'],
    ],

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
