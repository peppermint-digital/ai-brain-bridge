# peppermint/ai-brain-bridge

Einheitlicher Kommunikationslayer zwischen den Peppermint-Produkten
(Manager, CRM, Verwaltung, Crewtex Shop) und **AI Brain** — als
Laravel-Composer-Paket.

Vertrag/Architektur: Wiki **„Peppermint ↔ AI Brain — Einheitlicher
Kommunikationslayer (v1)"** in AI Brain.

## Modell — zwei Schienen, AI Brain als Hub

| Schiene | Wofür | Mechanismus |
|---|---|---|
| **MCP** | synchrone Daten & Aktionen (beide Richtungen) | MCP-Tool-Calls über OAuth |
| **Events** | asynchrone Benachrichtigungen (beide Richtungen) | signierter Event-Bus |

Produkte reden **nur** mit AI Brain, nie direkt miteinander.

## Installation

```bash
composer require peppermint/ai-brain-bridge
php artisan vendor:publish --tag=ai-brain-bridge-config
```

`.env`:
```dotenv
AI_BRAIN_URL=https://brain.proxy.peppermint-digital.com
AI_BRAIN_PRODUCT_SLUG=peppermint-verwaltung
AI_BRAIN_CLIENT_ID=…
AI_BRAIN_CLIENT_SECRET=…
AI_BRAIN_EVENT_SECRET=…           # HMAC-Secret für den Event-Bus
# optional: AI_BRAIN_MCP_URL, AI_BRAIN_EVENTS_URL, AI_BRAIN_WEBHOOK_ROUTE
```

## Nutzung

### MCP — Daten & Aktionen (synchron)
```php
use Peppermint\AiBrainBridge\Facades\AiBrain;

// Ein AI-Brain-Tool aufrufen
$tasks = AiBrain::call('list-tasks-tool', ['project' => 'peppermint-verwaltung']);
AiBrain::call('create-task-tool', ['project' => '…', 'title' => 'Aus Mail erstellt']);

// Beliebiger MCP-Server (z.B. ein anderes Produkt über den Hub)
$res = AiBrain::mcp('https://pm.peppermint-digital.com/mcp/peppermint')->callTool('…', [...]);
```

### Channels — „ein Chat pro Channel"
Läuft über den **einheitlichen MCP-Weg** (`/mcp/brain`, OAuth `mcp:use`) — dieselbe
Schiene wie alle anderen Tools.
```php
// Welche Channels darf ich ansprechen?
$channels = AiBrain::channels();                 // ['price-research', 'offer-extraction']

// Anfrage in den Channel-Chat (payload und/oder Freitext)
$r = AiBrain::channel('price-research')->message(
    ['product' => 'Hoodie STSU177', 'menge' => 23],
    ref: 'product-1234',
);
$chatId = $r['chat_id'];

// Status + Thread pollen (status: queued|running|needs_input|done|failed)
$thread = AiBrain::channel('price-research')->thread($chatId);

// Auf eine needs_input-Rückfrage antworten
AiBrain::channel('price-research')->reply($chatId, 'EK 23 €');
```
Der Agent schreibt Ergebnisse direkt per Produkt-MCP zurück; Rückfragen kommen als
`channel.reply`-Event oder per Poll (`status == 'needs_input'`).

> `invoke()`/`messages()` bleiben als deprecated Aliase erhalten; neuer Code nutzt
> `message()`/`thread()` und das Feld `chat_id` (nicht mehr `invocation_id`).

### Events — asynchron (beide Richtungen)
```php
// raus an AI Brain (signiert, idempotent, retried)
AiBrain::emit('offer.created', ['id' => $offer->id, 'total' => 99.0], entityRef: "offer:{$offer->id}");

// rein (in einem ServiceProvider::boot)
AiBrain::on('task.completed', function ($event) {
    // $event->payload, $event->entityRef …
});
// …oder klassisch auf Peppermint\AiBrainBridge\Events\AiBrainEventReceived lauschen.
```

Eingehende Webhooks landen auf `POST /webhooks/ai-brain` (konfigurierbar),
signaturgeprüft (HMAC-SHA256) + idempotent.

## MCP-Server dieses Produkts exponieren
Dieses Paket setzt auf `laravel/mcp` auf. Definiere deine Tools dort; AI Brain
(bzw. der Agent) ruft sie über den Produkt-MCP-Endpoint auf. Eintrag in der
AI-Brain-„ConnectedProduct"-Registry (URLs, OAuth-Client, Secret).

## Status
v0.1.

✅ **MCP-Transport gegen das echte `/mcp/brain` (laravel/mcp) verifiziert**:
`initialize` → 200 `application/json` + `MCP-Session-Id`; `tools/call`
liefert `result.content[].text`. OAuth client-credentials (`mcp:use`)
funktioniert. (laravel/mcp akzeptiert `tools/call` auch ohne Session.)

Offen (P2): Outbox für garantierte Event-Zustellung; Tool-/Event-Katalog-
Generierung; AI-Brain-Seite `/api/v1/events` (Event-Bus, AI-Brain Task #2116).
