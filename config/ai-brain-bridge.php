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
    | „Mit AI Brain anmelden" (AI Brain #5266) — authorization_code + PKCE,
    | Scope `identity`. Standard AUS: Ein Produkt schaltet den Knopf frei, wenn
    | sein Anmelde-Client in AI Brain angelegt ist (`comm-layer:login-client`).
    |
    | Der bestehende Anmeldeweg bleibt davon unberührt. Dies ist ein zweiter
    | Knopf, kein Ersatz.
    */
    'login' => [
        'enabled' => filter_var(env('AI_BRAIN_LOGIN', false), FILTER_VALIDATE_BOOL),
        'client_id' => env('AI_BRAIN_LOGIN_CLIENT_ID'),
        'client_secret' => env('AI_BRAIN_LOGIN_CLIENT_SECRET'),
        'scope' => env('AI_BRAIN_LOGIN_SCOPE', 'identity'),
        // Guard, in dem angemeldet wird — und dessen Nutzer-Modell die Person
        // über die E-Mail gesucht wird.
        'guard' => env('AI_BRAIN_LOGIN_GUARD', 'web'),
        'redirect_path' => env('AI_BRAIN_LOGIN_START', '/auth/brain/redirect'),
        'callback_path' => env('AI_BRAIN_LOGIN_CALLBACK', '/auth/brain/callback'),
        'after_login' => env('AI_BRAIN_LOGIN_AFTER', '/dashboard'),
        // Wohin bei einem Fehlschlag — die gewohnte Anmeldemaske des Produkts.
        'failure_route' => env('AI_BRAIN_LOGIN_FAILURE_ROUTE', 'login'),
        'middleware' => ['web'],
        'timeout' => (int) env('AI_BRAIN_LOGIN_TIMEOUT', 15),
        'button_label' => env('AI_BRAIN_LOGIN_LABEL', 'Mit AI Brain anmelden'),

        /*
        | Der LOKALE Anmeldeweg (AI Brain #5346).
        |
        | 'an'        — unveraendert, jeder kann sich mit Passwort anmelden.
        | 'notzugang' — nur noch die unten genannten Adressen; alle anderen
        |               gehen ueber AI Brain, und damit gilt dessen Passwort-
        |               und MFA-Richtlinie auch hier.
        |
        | VORGABE IST 'an'. Ein Riegel, der sich beim Einspielen des Pakets von
        | selbst schliesst, sperrt beim ersten Deploy alle aus.
        |
        | Die Ausnahmeliste ist der Notzugang: Faellt AI Brain aus, kommen diese
        | Menschen trotzdem herein — um zu arbeiten oder zu reparieren. Sie ist
        | zugleich der schwaechste Punkt der Kette (fuer sie gilt Brains
        | MFA-Pflicht nicht) und gehoert deshalb kurz gehalten. Diese Konten
        | brauchen hier ein starkes Passwort und eigenes MFA.
        */
        'local' => env('AI_BRAIN_LOCAL_LOGIN', 'an'),
        'local_except' => env('AI_BRAIN_LOCAL_LOGIN_EXCEPT', ''),
    ],

    /*
    | Umschaltleiste zwischen den Peppermint-Systemen (AI Brain #5281).
    |
    | Ein Griff am oberen Bildschirmrand, hinter dem die Systeme liegen, in die
    | sich diese Person anmelden darf. Was dort steht, entscheidet ausschliesslich
    | das Verzeichnis in AI Brain — dieses Produkt fragt nur.
    |
    | AUS als Vorgabe: Solange nichts eingeschaltet ist, registriert das Paket
    | die Routen gar nicht, und im Produkt aendert sich nichts.
    |
    | Ohne `login.enabled` bleibt die Leiste ebenfalls weg — ein Knopf, der in
    | ein System fuehrt, in dem der gemeinsame Anmeldeweg fehlt, endet auf der
    | Anmeldemaske. Das ist genau der Weg, den sie ersparen soll.
    */
    'switcher' => [
        'enabled' => filter_var(env('AI_BRAIN_SWITCHER', false), FILTER_VALIDATE_BOOL),
        // Wie lange die Liste je Person zwischengespeichert wird.
        //
        // Die SYSTEME aendern sich im Monat vielleicht einmal — dafuer waeren
        // fuenf Minuten reichlich knapp bemessen. Die MERKZETTEL aendern sich
        // dagegen mitten im Arbeiten, und sie stehen in derselben Antwort: Wer
        // in einem System einen anlegt, will ihn im naechsten sehen und nicht
        // erst nach der Pause. Eine Minute ist der Kompromiss.
        'cache_seconds' => (int) env('AI_BRAIN_SWITCHER_CACHE', 60),
        'timeout' => (int) env('AI_BRAIN_SWITCHER_TIMEOUT', 8),
        'apps_path' => env('AI_BRAIN_SWITCHER_APPS', '/ai-brain/switcher/apps'),
        'script_path' => env('AI_BRAIN_SWITCHER_SCRIPT', '/ai-brain/switcher/app-switcher.js'),
        'go_path' => env('AI_BRAIN_SWITCHER_GO', '/auth/brain/go'),
        'pins_path' => env('AI_BRAIN_SWITCHER_PINS', '/ai-brain/switcher/pins'),
        'middleware' => ['web'],
    ],

    /*
    | System zu System ueber Brain (AI Brain #5243) — der Ersatz fuer die
    | Peer-Schiene. Es ist nichts einzurichten: Der Weg nutzt dieselbe
    | OAuth-Anbindung wie alles andere. Nur die Zeitgrenze steht hier.
    */
    'gateway' => [
        'timeout' => (int) env('AI_BRAIN_GATEWAY_TIMEOUT', 20),
    ],

    /*
    | MCP — synchrone Daten/Aktionen (Schiene 1).
    */
    'mcp' => [
        'brain_url' => env('AI_BRAIN_MCP_URL'),
        'timeout' => (int) env('AI_BRAIN_MCP_TIMEOUT', 30),
    ],

    /*
    | Acting-User-Delegation (Connector Phase 4.1). Das Produkt deklariert pro
    | MCP-Call den handelnden End-User; Brain handelt dann als dieser User statt
    | als Owner (korrekte Attribution + Sichtbarkeit/Rechte).
    |
    | SICHERER DEFAULT (ab 1.2): ist hier NICHTS gesetzt, schickt die Bridge den
    | aktuell authentifizierten User als handelnde Person mit. Ein frisch
    | installiertes Produkt ist damit von sich aus korrekt zugeordnet.
    |
    | Vorher bedeutete „kein Resolver" stillschweigend „niemand" — jeder Aufruf
    | lief ohne Person, und das fiel nirgends auf. Wer wirklich ohne Person
    | handeln will (Hintergrund-Jobs, Health-Checks, Infra), schreibt das hin:
    |
    |     AiBrain::asService(fn () => AiBrain::call('...'));
    |
    | `resolver` überschreibt den Default und ist nur nötig, wenn die handelnde
    | E-Mail anders ermittelt wird als über `auth()`. Das Produkt darf dabei NUR
    | den authentifizierten User behaupten, niemals ungeprüften Input — AI Brain
    | prüft die Behauptung per HMAC-Signatur und weist unbekannte E-Mails ab.
    |
    | Alternativ zur Config zur Laufzeit setzbar:
    | AiBrain::resolveActingUserUsing(fn () => auth()->user()?->email);
    */
    'acting_user' => [
        'resolver' => null,
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
        // Gegenrichtung (#471): Auf welches Modell wird die von AI Brain
        // behauptete Acting-User-E-Mail gemappt? Produkte mit abweichendem
        // Mapping nutzen stattdessen AiBrain::resolveInboundUserUsing().
        'user_model' => env('AI_BRAIN_INBOUND_USER_MODEL', 'App\\Models\\User'),

        'route' => env('AI_BRAIN_WEBHOOK_ROUTE', '/webhooks/ai-brain'),
        'middleware' => ['api'],
        'idempotency_ttl' => (int) env('AI_BRAIN_IDEMPOTENCY_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | App-Health melden (K7, AI Brain #5239)
    |--------------------------------------------------------------------------
    | Kam aus dem eigenstaendigen Paket `ai-brain/laravel-connector` und wohnt
    | jetzt hier. Die ENV-Namen bleiben die alten (`AI_BRAIN_CONNECTOR_*`):
    | Ein Produkt, das das alte Paket entfernt, muss dadurch nichts umtragen —
    | ein Umbenennen haette hier nur Arbeit erzeugt und nichts verbessert.
    |
    | Solange das alte Paket noch installiert ist, meldet DIESER Weg nichts:
    | Der ServiceProvider tritt zurueck, sonst liefe die Meldung doppelt.
    |
    | Transport (URL, OAuth-Token) ist derselbe wie fuer alles andere — kein
    | eigenes Secret, keine eigene Adresse.
    */
    'health' => [
        'enabled' => (bool) env('AI_BRAIN_CONNECTOR_ENABLED', true),

        // Projekt-Slug in AI Brain. Leer lassen: AI Brain leitet es aus der
        // Anbindung ab (der OAuth-Client ist auf ein Projekt gescopet).
        'project' => env('AI_BRAIN_CONNECTOR_PROJECT', ''),

        // Anzeigename der App. Leer lassen: dann gilt der Produkt-Slug der
        // Anbindung, erst danach APP_NAME.
        'app_name' => env('AI_BRAIN_CONNECTOR_APP'),

        'schedule' => env('AI_BRAIN_CONNECTOR_SCHEDULE', 'everyFiveMinutes'),

        // Aggregierte Fehler zwischen zwei Meldungen: Klasse, Ort, Haeufigkeit.
        // Keine Stacktraces, keine Nutzlasten.
        'exceptions' => [
            'enabled' => (bool) env('AI_BRAIN_CONNECTOR_EXCEPTIONS', true),
            'max_items' => (int) env('AI_BRAIN_CONNECTOR_EXCEPTIONS_MAX', 10),
        ],

        // Langsame Abfragen oberhalb der Schwelle, nach normalisiertem SQL
        // zusammengefasst. Bindings werden nie uebertragen.
        'slow_queries' => [
            'enabled' => (bool) env('AI_BRAIN_CONNECTOR_SLOW_QUERIES', true),
            'threshold_ms' => (int) env('AI_BRAIN_CONNECTOR_SLOW_QUERY_MS', 1000),
            'max_items' => (int) env('AI_BRAIN_CONNECTOR_SLOW_QUERIES_MAX', 10),
        ],
    ],
];
