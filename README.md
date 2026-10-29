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

### Gateway — ein Vorgang bei einem ANDEREN Produkt (K11)

Man nennt einen **Vorgang**, kein Zielsystem. Wer ihn bedient, weiß das
Fähigkeits-Register in AI Brain — gemessen, nicht gepflegt. Damit muss die
Verwaltung nicht wissen, wo Aufgaben liegen; sie muss nur wissen, dass sie
welche braucht.

```php
$r = AiBrain::gateway('tasks.create', ['title' => 'Aus Ticket 5']);
// ['ok' => true, 'data' => [...], 'product' => 'peppermint-manager', ...]
```

Fehler sind unterscheidbar und schlagen **nicht** als Ausnahme hoch:
`not_offered` (das kann gerade niemand), `not_allowed` (dieses System darf das
nicht), `unreachable` (das Ziel antwortet nicht), `tool_failed` (das Ziel hat
abgelehnt). Fail-closed: Ohne Freigabe in Brains `gateway_grants` ist der Weg
zu — **die Freigabe wird eingetragen, BEVOR das Produkt umgebaut wird.**
Andersherum stehen Auswahllisten leer da, mit „nicht freigeschaltet" im Log,
und es fällt niemandem auf.

**Und wer bedient ihn?**

```php
AiBrain::providers('calendar.events.list');
// [[
//   'slug' => 'peppermint-manager',
//   'name' => 'Peppermint Manager',
//   'key'   => 'manager',                        // Slug ohne das gemeinsame Präfix
//   'label' => 'Manager',                        // Name ohne „Peppermint"
//   'url'  => 'https://pm…',                      // fürs Sprungziel, nicht die MCP-Adresse
//   'capabilities' => ['calendar.events.list', …], // was es kann UND wir dürfen
// ]]
```

`key` und `label` werden **hier** abgeleitet und nicht in jedem Produkt neu.
Das ist keine Bequemlichkeit: Der Schlüssel steckt in den Termin-Kennungen
(`manager:7`) und in den gespeicherten Filtern der Nutzer. Leitete ihn jedes
Produkt selbst ab, hieße dasselbe System in zwei Oberflächen irgendwann
verschieden — und ein gespeicherter Filter wäre stumm falsch, ohne dass etwas
bricht.

`capabilities` ist die **Schnittmenge** aus dem, was das System anbietet, und
dem, was dieses hier auslösen darf. Ohne sie müsste man je Vorgang erneut
fragen — für einen Kalender sechsmal, nur um zu wissen, ob „löschen"
erscheinen darf. Und raten darf man nicht: Ein Knopf, der beim Drücken
abgewiesen wird, ist schlechter als keiner.

Das Gegenstück zu `gateway()`, das das Zielsystem bewusst verschweigt. Für
einen Kalender ist es Inhalt: Jedes angebundene System wird eine eigene Quelle
mit Farbe, Filterknopf und „hier anlegen". Zurück kommen nur Systeme, für die
dieses hier freigeschaltet ist; das eigene steht nicht darin, und ein
Fehlschlag ist eine leere Liste — der eigene Kalender soll nicht leer werden,
weil die Auskunft fehlt.

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

## Rechte zentral — Rollen und Zuständigkeiten aus AI Brain

Alle Rechte einer Person in einem Produkt werden in AI Brain gesetzt. Das Produkt
meldet, was es zu vergeben hat, und setzt es auf Zuruf; die Riegel bleiben hier.

**Rollen** (AI Brain #5245/#5314) — `ListRolesTool` + `AssignRoleTool` am
MCP-Server registrieren. Ohne eigenen Resolver liest `RoleCatalog` die Rollen von
`spatie/laravel-permission`.

**Zuständigkeiten** (#9007) — das, woran jemand arbeitet: im CRM Mandant und
darunter Bereich. Zwei Werkzeuge, zwei Resolver, keine Vorgabe:

```php
// MCP-Server des Produkts
protected array $tools = [
    \Peppermint\AiBrainBridge\Mcp\Tools\ListScopesTool::class,
    \Peppermint\AiBrainBridge\Mcp\Tools\AssignScopeTool::class,
];

// ServiceProvider
ScopeCatalog::resolveUsing(fn () => [
    ['schluessel' => 'mandant', 'beschriftung' => 'Mandant',
     'alle_moeglich' => true, 'alle_beschriftung' => 'Alle Mandanten',
     'werte' => [['id' => '3', 'label' => 'Schülerferienpass']]],
    ['schluessel' => 'bereich', 'beschriftung' => 'Bereich', 'eltern' => 'mandant',
     'werte' => [['id' => '12', 'label' => 'NRW', 'eltern_id' => '3']]],
]);

ScopeCatalog::resolvePersonUsing(fn (string $email) => [
    'zugewiesen' => ['mandant' => [3], 'bereich' => [12]],
    'alle' => ['mandant' => false],
]);

ScopeAssignment::handleUsing(function (string $email, string $ebene, array $ids, ?bool $alle) {
    // Hier liegen die Riegel: Gehoert der Bereich zu einem zugewiesenen
    // Mandanten? Ist der Mandant noch aktiv? AI Brain weiss das nur aus
    // zweiter Hand.
    return ['ok' => true, 'message' => 'Bereiche gesetzt.', 'zugewiesen' => [...], 'alle' => [...]];
});
```

`ids` trägt immer die **vollständige** Liste einer Ebene — wie `sync()`. Eine
leere Liste entzieht; es gibt bewusst kein zuweisen/entziehen wie bei Rollen.

**Ohne Resolver gibt es die Fähigkeit nicht.** Ein Produkt ohne
Zuständigkeiten meldet eine leere Ebenenliste, und in AI Brain erscheint nichts.
Der Schalter ist die Registrierung.

## Nutzerverwaltung: zentral ODER lokal, nie beides

Ist das Produkt an AI Brain angebunden, werden Konten, Rollen, Mandanten und
Bereiche **dort** vergeben — die lokale Maske zeigt nur noch, was gilt.

```env
# auto (Vorgabe): angebunden => brain, sonst lokal
AI_BRAIN_USER_ADMIN=auto
```

Die Vorgabe ergibt sich aus der Anbindung und nicht aus einer Zeile je Site:
Vergisst man sie bei einem Produkt, bliebe dort unbemerkt eine zweite Wahrheit
über dieselben Rechte stehen. Anders als beim Anmelde-Riegel sperrt hier nichts
aus — es verschiebt nur, WO man die Rechte setzt.

Das Produkt hängt den Riegel an seine Verwaltungsroute (Lesen bleibt offen):

```php
Route::resource('users', UserController::class)->middleware('ai-brain.user-admin');
```

Und noch einmal in der Policy, denn ein Riegel vor der Tür sagt nichts über die
Fenster:

```php
public function create(User $nutzer): bool
{
    return Nutzerverwaltung::lokal() && $nutzer->isAdmin();
}
```

Für die Oberfläche: `Nutzerverwaltung::fuerOberflaeche()` liefert `zentral`,
`modus`, `url` und einen Hinweissatz — als geteilte Inertia-Prop oder
View-Variable.

**Nicht betroffen:** der Notzugang (`AI_BRAIN_LOCAL_LOGIN`), das eigene Profil
und die Mitarbeiterdaten des Managers (Urlaub, Stundenlisten, Personalstamm,
Offboarding). Die Grenze ist nicht „hängt an einem Nutzer", sondern
„entscheidet, wer was darf oder sieht".

Wo beides auf **derselben Route** liegt — im Manager trägt `admin/users/{user}`
die Rolle *und* den Urlaubsanspruch —, läuft der Schnitt über die Felder:

```php
[$validated, $entfernt] = Nutzerverwaltung::ohneRechtefelder($validated, [
    'name', 'email', 'password', 'role', 'is_active',
]);

// Still weglassen waere die schlechteste Rueckmeldung von allen.
if ($entfernt !== []) {
    session()->flash('warning', Nutzerverwaltung::abweisung());
}
```

## Umschaltleiste zwischen den Systemen

Ein Griff am oberen Bildschirmrand: Maus dagegen, und die Systeme fahren aus, in
die sich diese Person anmelden darf. Wer dort steht, entscheidet ausschliesslich
das Verzeichnis in AI Brain — dieses Produkt fragt nur.

```env
AI_BRAIN_SWITCHER=true
```

Eine Zeile im Layout (`resources/views/app.blade.php`), vor `</body>`:

```blade
@aiBrainSwitcher
```

Das war alles. Die Leiste selbst liefert das Paket aus; sie laeuft in React- und
Blade-Anwendungen gleich, weil sie ein Web-Component in reinem JavaScript ist —
ohne Bauschritt in der einbindenden Anwendung.

**Sie haengt an zwei Schaltern.** Ohne `AI_BRAIN_LOGIN` bleibt sie weg — ein Knopf,
der in ein System fuehrt, in dem der gemeinsame Anmeldeweg fehlt, endet auf einer
Anmeldemaske. Genau das soll sie ersparen.

**Was beim Klick passiert.** Der Knopf zeigt auf `/auth/brain/go` des Ziels:
Sitzung vorhanden → sofort weiter, sonst → durch den Anmeldeweg. Wohin es geht,
bestimmt das Zielprodukt ueber `AI_BRAIN_LOGIN_AFTER` — nicht AI Brain.

**Wenn AI Brain ausfaellt**, erscheint keine Leiste. Sie ist Beiwerk und darf nie
der Grund sein, dass eine Seite nicht laedt.

### Die Postfach-Kachel

In der Leiste sitzt eine Kachel mit der Zahl des Posteingangs. Sie erscheint
nur, wenn das Produkt eine Posteingangs-Seite **und** eine Zahl dazu
registriert hat — ein Symbol, das auf einen 404 führt, ist schlimmer als
keines. Dieselbe Frage beantwortet `SwitcherController::zeigtPostfach()` dem
Produkt, das seine eigene Glocke genau dann weglässt.

Gewählt wird in dieser Reihenfolge:

| | Seite | Zahl |
|---|---|---|
| **1. Vorzug** | `posteingang` — der gemeinsame Posteingang | `inbox.ungelesen` — global, alle Arten und Systeme |
| **2. Rückfall** | `notifications.inbox` — die produkteigene Meldungsseite | `inbox.api.unread-count` — nur die Meldungen dieses Systems |

Bis zum 29.10.2026 stand hier fest der Rückfall. Die Kachel führte damit auf
einen Ausschnitt, während das Posteingangs-Symbol in der Kopfzeile desselben
Produkts auf den gemeinsamen Posteingang ging: zwei Briefkästen mit
verschiedenem Inhalt in derselben Kopfzeile (AI Brain #1152).

Der Rückfall bleibt aus zwei Gründen: Ein Produkt ohne `peppermint/inbox` hat
keine gemeinsame Seite — und zwischen dem Ziehen dieses Pakets und dem Ziehen
des Inbox-Pakets liegt in jedem Produkt eine Lücke, in der die Kachel
stehenbleiben soll statt zu verschwinden.

### Landet man auf der richtigen Seite?

Zeigt `/` im Produkt eine Willkommensseite, landet man beim Umschalten dort —
und muss ein zweites Mal klicken. Angemeldete gehoeren aufs Dashboard:

```php
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');
```

## Status
v0.1.

✅ **MCP-Transport gegen das echte `/mcp/brain` (laravel/mcp) verifiziert**:
`initialize` → 200 `application/json` + `MCP-Session-Id`; `tools/call`
liefert `result.content[].text`. OAuth client-credentials (`mcp:use`)
funktioniert. (laravel/mcp akzeptiert `tools/call` auch ohne Session.)

Offen (P2): Outbox für garantierte Event-Zustellung; Tool-/Event-Katalog-
Generierung; AI-Brain-Seite `/api/v1/events` (Event-Bus, AI-Brain Task #2116).
