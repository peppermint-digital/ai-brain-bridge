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
```php
$r = AiBrain::channel('price-research')->invoke(
    ['product' => 'Hoodie STSU177', 'menge' => 23],
    ref: 'product-1234',
);
$thread = AiBrain::channel('price-research')->messages($r['invocation_id']);
AiBrain::channel('price-research')->reply($r['invocation_id'], 'EK 23 €');
```
Der Agent schreibt Ergebnisse direkt per Produkt-MCP zurück; Rückfragen
kommen als `channel.reply`-Event oder per Poll.

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
v0.1 — Skelett. Offene Punkte (P2): MCP-HTTP-Transport gegen den echten
`laravel/mcp`-Streamable-HTTP verifizieren (Initialize-Handshake/Session),
Outbox für garantierte Event-Zustellung, Tool-/Event-Katalog-Generierung.
