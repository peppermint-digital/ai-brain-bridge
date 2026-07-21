# Identitäts-Modi: Service vs. User

Jeder Call über das SDK läuft in AI Brain unter **einer Identität**. Es gibt zwei
Modi — und die Wahl ist **pro Call**, nicht pro Produkt. Ein Produkt nutzt in der
Regel *beide*: Service für Hintergrund/Infra, User für nutzergetriggerte Aktionen.

| Modus | Identität in AI Brain | Wofür |
|---|---|---|
| **Service** | *kein* User (`auth()->user()` ist `null`) — der OAuth-Client (das Produkt) ist die Identität | Monitoring, Health-Checks, Hintergrund-Jobs, Infra, jeder Call ohne echten Menschen dahinter |
| **User** | der delegierte **Brain-User** (aufgelöst aus der behaupteten E-Mail) | Aktionen, die ein echter End-Nutzer im Produkt auslöst |

> **Wichtig:** Es gibt **keinen** künstlichen „Service-User". Service-Calls laufen
> bewusst *userlos* — die Identität ist der Client selbst, kein Mensch und kein
> Platzhalter-Account.

---

## Service-Modus

Kein Acting-User wird mitgeschickt. In AI Brain ist `auth()->user()` `null`; die
Aktion wird nicht einem Menschen zugeschrieben.

**Wann greift er?**

1. **Automatisch**, wenn es keinen eingeloggten Nutzer gibt — Konsole, Queue,
   Scheduler, Background-Jobs. (Der Acting-User-Resolver liefert dann `null` →
   kein Header → Service.)
2. **Explizit** via `asService()` — auch *innerhalb* eines Web-Requests, wenn der
   Call keine Nutzeraktion ist (z.B. ein Verbindungs-/Health-Check, den ein
   eingeloggter Nutzer nur *auslöst*, der aber Service-Charakter hat):

```php
use Peppermint\AiBrainBridge\Facades\AiBrain;

// Health-/Verbindungs-Check: läuft als Service, NICHT als der eingeloggte Nutzer
$channels = AiBrain::asService(fn () => AiBrain::channels());
```

`asService()` unterdrückt den Acting-User-Resolver nur für die Dauer des Callbacks
und stellt ihn danach immer wieder her (kein globaler Leak).

> ⚠️ **Goldene Regel:** Health-Checks, Sync- und Infra-Calls **immer** in
> `asService()` kapseln — sonst delegieren sie als der zufällig eingeloggte
> Nutzer, und wenn der AI Brain unbekannt ist, gibt es **HTTP 403**. (Genau dieser
> Fehler ist uns beim Manager-Verbindungs-Check passiert.)

---

## User-Modus (Acting-User-Delegation)

Das SDK schickt die E-Mail des **aktuellen End-Nutzers** als signierten
Acting-User-Header mit. AI Brain löst sie zu einem Brain-User auf und führt die
Aktion in dessen Namen aus (Sichtbarkeit, Audit, Zuordnung).

```php
// In einem Controller/Request, mit eingeloggtem Nutzer:
AiBrain::call('create-task-tool', ['project' => '…', 'title' => 'Aus der App']);
// → in AI Brain als der delegierte Brain-User angelegt, nicht als „das Produkt"
```

**Auflösung in AI Brain:**

1. **Mapping-Override** — falls für das Produkt ein `product_user_mapping`
   (Produkt-E-Mail → Brain-User) existiert.
2. sonst **direkter E-Mail-Match** auf einen Brain-User.
3. **kein Treffer → HTTP 403** („Unknown acting user"). Nur bekannte bzw.
   gemappte Nutzer dürfen als sie selbst handeln — „nur Brain-Nutzer nutzen
   Brain-Features".

Die Assertion wird mit dem geteilten Event-Secret **HMAC-signiert** (das SDK macht
das automatisch, sobald ein Secret konfiguriert ist).

### Resolver einrichten

Der Resolver liefert die E-Mail des aktuell handelnden Nutzers — und **niemals
ungeprüften Input**, nur den authentifizierten User:

```php
// AppServiceProvider::boot()
use Peppermint\AiBrainBridge\Facades\AiBrain;

AiBrain::resolveActingUserUsing(fn () => auth()->user()?->email);
```

Alternativ über die Config `ai-brain-bridge.acting_user.resolver`. Ist **kein**
Resolver gesetzt (oder liefert er `null`), läuft jeder Call automatisch im
Service-Modus.

Produkt-Nutzer, deren E-Mail nicht mit ihrem Brain-Account übereinstimmt, werden
über **User-Mappings** in der AI-Brain-UI (Connected Products) zugeordnet.

---

## Entscheidungsbaum

```
Löst ein echter End-Nutzer die Aktion aus?
├─ ja  → User-Modus (Standard, sofern Resolver gesetzt + Nutzer eingeloggt)
└─ nein → Service-Modus
          ├─ läuft in Konsole/Queue/Scheduler? → automatisch userlos
          └─ läuft im Web-Request (Health/Infra)? → explizit AiBrain::asService(…)
```

---

## In der AI-Brain-UI

Auf der **Connections → Products**-Seite trägt jedes angebundene Produkt Badges,
die zeigen, welche Modi es tatsächlich nutzt:

- **Service** — schickt userlose Calls (z.B. Health-Push des Connectors).
- **User** — delegiert echte End-Nutzer (Acting-User-Mappings vorhanden).
- **intern** — lokaler Dienst auf dem Host (kein externes Produkt).

Ein Produkt kann **beide** tragen.

Siehe auch: [../README.md](../README.md), der `ai-brain/laravel-connector`
(reiner Service-Modus-Verbraucher) und die Wiki-Seite „Einheitlicher
Kommunikationslayer" in AI Brain.
