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

AI Brain ist der Hub für beide Schienen. Daneben können Produkte über eine
**Peer-Verbindung** auch direkt miteinander sprechen (`PeerClient` +
`peer.acting-user`) — das läuft nicht über AI Brain und ist eine Abmachung
zwischen den beteiligten Produkten.

## Identitäts-Modi — Service vs. User

Jeder Call läuft unter einer von zwei Identitäten (Wahl **pro Call**, ein Produkt
nutzt meist beide):

- **Service** (userlos) — für Monitoring, Health-Checks, Background/Infra. Kein
  Acting-User, die Identität ist der Client selbst. Automatisch in Konsole/Queue,
  explizit via `AiBrain::asService(fn () => …)`.
- **User** (Delegation) — für Aktionen echter End-Nutzer. Das SDK schickt den
  aktuellen Nutzer (signiert), AI Brain handelt in dessen Namen.

📖 **Ausführlich mit Beispielen, Entscheidungsbaum und der „asService()-Goldregel":
[`docs/identity-modes.md`](docs/identity-modes.md).**

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

## App-Health melden

Seit K7 (AI Brain #5239) meldet dieses Paket auch den Gesundheitszustand der App
an AI Brain — alle fünf Minuten, über denselben MCP-Weg wie alles andere. Kein
eigenes Secret, keine eigene Adresse.

```bash
php artisan ai-brain:push-health --dry-run   # zeigt den Schnappschuss, sendet nicht
```

Erfasst werden Queue-Rückstand, fehlgeschlagene Jobs, Datenbank-Erreichbarkeit,
aggregierte Fehler (Klasse, Ort, Häufigkeit — **keine** Stacktraces, **keine**
Nutzlasten) und langsame Abfragen nach normalisiertem SQL (**ohne** Bindings).

### Umstieg vom Paket `ai-brain/laravel-connector`

Das Paket ist abgelöst; sein Inhalt wohnt jetzt hier. Der Umstieg ist ein
Handgriff und braucht **keine** Änderung an der `.env` — die Variablennamen
(`AI_BRAIN_CONNECTOR_*`) sind absichtlich dieselben geblieben:

```bash
composer remove ai-brain/laravel-connector
```

Solange das alte Paket installiert ist, **tritt dieses hier zurück** und meldet
nichts. So kann jedes Produkt zu seinem eigenen Deploy umsteigen, ohne dass die
Meldung zwischenzeitlich doppelt läuft.

## Status
v0.1.

✅ **MCP-Transport gegen das echte `/mcp/brain` (laravel/mcp) verifiziert**:
`initialize` → 200 `application/json` + `MCP-Session-Id`; `tools/call`
liefert `result.content[].text`. OAuth client-credentials (`mcp:use`)
funktioniert. (laravel/mcp akzeptiert `tools/call` auch ohne Session.)

Offen (P2): Outbox für garantierte Event-Zustellung; Tool-/Event-Katalog-
Generierung; AI-Brain-Seite `/api/v1/events` (Event-Bus, AI-Brain Task #2116).
