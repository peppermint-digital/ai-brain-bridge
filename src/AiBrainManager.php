<?php

namespace Peppermint\AiBrainBridge;

use Illuminate\Support\Facades\Event;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Channels\ChannelClient;
use Peppermint\AiBrainBridge\Config\BridgeConfig;
use Peppermint\AiBrainBridge\Events\AiBrainEventReceived;
use Peppermint\AiBrainBridge\Events\Event as BridgeEvent;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Gateway\GatewayClient;
use Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser;
use Peppermint\AiBrainBridge\Mcp\McpClient;

/**
 * Zentrale Fassade des Bridge-SDK. Kapselt beide Schienen:
 *  - MCP (synchron): call(), brain(), mcp()
 *  - Channels (Spezial-MCP): channel()
 *  - Events (async): emit(), on()
 *
 * Jedes Produkt nutzt nur diese API — kein rohes JSON-RPC/HTTP/Auth/Retry.
 */
class AiBrainManager
{
    /**
     * Acting-User-Resolver (Connector Phase 4.1): liefert die E-Mail des aktuell
     * eingeloggten Produkt-Users oder null. Default aus der Config, zur Laufzeit
     * via resolveActingUserUsing() überschreibbar.
     *
     * @var (callable(): ?string)|null
     */
    protected $actingUserResolver;

    /**
     * Gegenrichtung (#471): AI Brain ruft UNSEREN MCP-Server auf und behauptet
     * pro Nachricht, wer gerade schreibt. Dieser Resolver mappt die behauptete
     * E-Mail auf das lokale Nutzer-Objekt. Default: `App\Models\User` per
     * E-Mail — Produkte mit abweichendem Mapping (z.B. `ai_brain_user_id`)
     * überschreiben ihn via resolveInboundUserUsing().
     *
     * @var (callable(string): mixed)|null
     */
    protected $inboundUserResolver;

    /**
     * Ausdrücklicher Systemaufruf (siehe {@see asService()}). Nur wenn das hier
     * true ist, läuft ein Call OHNE handelnde Person.
     *
     * Bewusst ein eigenes Flag und nicht „Resolver ist null": ein fehlender
     * Resolver bedeutet seit Version 1.2 den sicheren Default (der eingeloggte
     * User), nicht mehr „niemand". Sonst wäre der userlose Modus wieder das,
     * was er vorher war — der stille Normalfall, den man nicht sieht.
     */
    protected bool $serviceMode = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected OAuthTokenProvider $tokens,
        protected EventPublisher $publisher,
    ) {
        $resolver = $config['acting_user']['resolver'] ?? null;
        $this->actingUserResolver = is_callable($resolver) ? $resolver : null;
    }

    /**
     * Die E-Mail der handelnden Person für den aktuellen Call — die EINZIGE
     * Stelle, an der das entschieden wird.
     *
     * Reihenfolge:
     *  1. Ausdrücklicher Systemaufruf ({@see asService()}) ⇒ null, keine Person.
     *  2. Vom Produkt gesetzter Resolver ⇒ dessen Ergebnis.
     *  3. Sicherer Default: der authentifizierte User dieses Requests.
     *
     * Punkt 3 ist der Unterschied zu früher: ohne Konfiguration lief bisher
     * ALLES ohne handelnde Person, und niemandem fiel es auf. Ein frisch
     * installiertes Produkt schickt jetzt von sich aus den eingeloggten User
     * mit; wer wirklich ohne Person handeln will, muss das hinschreiben.
     *
     * In einem Hintergrund-Job gibt es keinen eingeloggten User — dort liefert
     * der Default null. Solche Aufrufe gehören ausdrücklich in asService().
     */
    public function actingUserEmail(): ?string
    {
        if ($this->serviceMode) {
            return null;
        }

        $email = is_callable($this->actingUserResolver)
            ? ($this->actingUserResolver)()
            : $this->authenticatedUserEmail();

        $email = is_string($email) ? trim($email) : '';

        return $email !== '' ? $email : null;
    }

    /**
     * Sicherer Default: die E-Mail des authentifizierten Users, sofern dieser
     * Request überhaupt einen hat. Bewusst defensiv — die Bridge läuft auch in
     * Anwendungen ohne `auth`-Binding (Konsole, Tests, schlanke Services).
     */
    protected function authenticatedUserEmail(): ?string
    {
        if (! function_exists('auth')) {
            return null;
        }

        try {
            $user = auth()->user();
        } catch (\Throwable) {
            return null;
        }

        $email = is_object($user) && isset($user->email) ? $user->email : null;

        return is_string($email) ? $email : null;
    }

    // ── MCP (Schiene 1) ──────────────────────────────────────────────────

    /**
     * Setzt den Acting-User-Resolver zur Laufzeit (Alternative zur Config).
     * Das Produkt darf NUR den authentifizierten User behaupten:
     * AiBrain::resolveActingUserUsing(fn () => auth()->user()?->email);
     *
     * @param  (callable(): ?string)|null  $resolver
     */
    public function resolveActingUserUsing(?callable $resolver): self
    {
        $this->actingUserResolver = $resolver;

        return $this;
    }

    /**
     * Setzt den Inbound-Resolver zur Laufzeit:
     * AiBrain::resolveInboundUserUsing(fn (string $email) => User::where('email', $email)->first());
     *
     * @param  (callable(string): mixed)|null  $resolver
     */
    public function resolveInboundUserUsing(?callable $resolver): self
    {
        $this->inboundUserResolver = $resolver;

        return $this;
    }

    /**
     * Löst die von AI Brain behauptete E-Mail auf ein lokales Nutzer-Objekt auf.
     * Kein Treffer ⇒ null; der Aufruf läuft dann unverändert weiter (Zuschreibung
     * ist best-effort, siehe Middleware).
     */
    public function resolveInboundUser(string $email)
    {
        if ($this->inboundUserResolver !== null) {
            return ($this->inboundUserResolver)($email);
        }

        $model = $this->config['inbound']['user_model'] ?? 'App\\Models\\User';

        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        return $model::query()->where('email', $email)->first();
    }

    /**
     * Das geteilte Secret, mit dem AI Brain die Acting-User-Behauptung signiert —
     * dasselbe wie beim Signieren ausgehend. Öffentlich, damit die Inbound-
     * Middleware prüfen kann, ohne die Config erneut zu interpretieren.
     */
    public function actingSecret(): ?string
    {
        return $this->actingSignatureSecret();
    }

    /**
     * Aus welchem AI-Brain-Channel kam der aktuelle eingehende Aufruf? `null`
     * bei allem, was nicht von dort stammt. Für Audit-/Zuschreibungszwecke
     * gedacht, NICHT für Rechteentscheidungen (siehe Middleware-Doc).
     */
    public function inboundChannel(): ?string
    {
        $channel = request()->attributes->get(
            \Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser::CHANNEL_ATTRIBUTE
        );

        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    /**
     * Führt $callback als SERVICE aus — ohne Acting-User-Delegation. Für
     * Health-Checks, Infra- und Hintergrund-Calls (kein End-User im Spiel):
     * sie laufen dann als Service-Principal in AI Brain, nicht als der zufällig
     * eingeloggte Mensch. Der Resolver wird nur für die Dauer des Callbacks
     * unterdrückt und im finally IMMER wiederhergestellt (kein globaler Leak).
     *
     * Der Connector nutzt diesen Weg implizit (Push aus der Konsole, kein User);
     * User-Aktionen bleiben delegiert. So ist der Zwei-Modi-Weg für ALLE Produkte
     * einheitlich:  AiBrain::asService(fn () => AiBrain::channels()).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function asService(callable $callback)
    {
        $previous = $this->serviceMode;
        $this->serviceMode = true;

        try {
            return $callback();
        } finally {
            $this->serviceMode = $previous;
        }
    }

    /**
     * Globales Event-Secret zum Signieren der Acting-User-Assertion (Phase 4.2).
     *
     * Null/leer ⇒ keine Signatur. AI Brain erzwingt sie, sobald dort ein
     * Event-Secret konfiguriert ist — das ist Plattform-Standard und KEIN
     * Produkt-Flag. Ohne Secret auf beiden Seiten trägt allein das
     * OAuth-Token die Authentifizierung (fail-safe für frische Installationen).
     */
    protected function actingSignatureSecret(): ?string
    {
        $secret = $this->config['events']['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Acting-User-Header für Direkt-HTTP-Aufrufe, die den McpClient umgehen
     * (z.B. Voice-Transcription gegen /api/v1/transcribe). Liefert denselben
     * `X-AI-Brain-Acting-User`(+ `-Sig`)-Header wie der McpClient — oder ein
     * leeres Array (kein Resolver / Hintergrund-Job / leere E-Mail) ⇒ der Call
     * läuft als Owner (heutiges Verhalten). Nur den authentifizierten User
     * durchreichen — der Resolver darf niemals ungeprüften Input liefern.
     *
     * @return array<string, string>
     */
    /**
     * Signiert die Acting-User-Behauptung gegenüber AI Brain.
     *
     * Der HMAC deckt **Produkt-Slug und E-Mail** ab (AI Brain, Bug #520). Vorher
     * war es die E-Mail allein — damit war jede Assertion ein universeller
     * Ausweis, der auch in fremden Kontexten galt: ein angebundenes Produkt
     * konnte damit die Git-Deploy-Keys beliebiger Channels abholen.
     *
     * Der Slug ist derselbe, unter dem AI Brain uns kennt (`source`, aus dem
     * One-Click-Claim). Stimmt er nicht, lehnt AI Brain die Signatur ab.
     */
    public static function signActingUser(string $email, string $secret): string
    {
        // Der Kontext MUSS der Slug sein, unter dem AI Brain uns kennt — sonst
        // schlaegt die Pruefung dort fehl (403). Massgeblich ist deshalb allein
        // der Claim-Store: dort steht `source` = `product_slug` aus dem
        // One-Click-Bundle.
        //
        // Ausdruecklich NICHT `config('ai-brain-bridge.source')`: diese Kette
        // faellt auf `APP_NAME` zurueck ("Peppermint Manager" statt
        // "peppermint-manager"). Der Wert ist also nie leer, sieht aber richtig
        // aus — und wuerde eine Signatur erzeugen, die AI Brain nicht kennt.
        $slug = BridgeConfig::load()['source'] ?? null;
        $slug = is_string($slug) ? trim($slug) : '';

        // Nicht ueber One-Click angebunden → kein belegbarer Slug → Altformat.
        // Schwaecher, aber gueltig; AI Brain nimmt es waehrend des Uebergangs an
        // und die Log-Warnung weist das Produkt als noch offen aus.
        if ($slug === '') {
            return self::signPeerActingUser($email, $secret);
        }

        return 'sha256='.hash_hmac('sha256', $slug.'|'.$email, $secret);
    }

    /**
     * Signatur für Aufrufe an ein anderes PRODUKT (Peer), nicht an AI Brain.
     *
     * Bewusst weiterhin das Altformat (nur E-Mail): Die Gegenseite prüft mit
     * {@see \Peppermint\AiBrainBridge\Http\Middleware\ResolvePeerActingUser},
     * und die Produkte aktualisieren dieses Paket nicht gleichzeitig. Würde hier
     * kontextgebunden signiert, verwürfe jeder Peer mit älterem Paket den Header
     * — die Aufrufe liefen weiter (Peer-Delegation ist Zuschreibung, keine
     * Autorisierung), aber die Datensätze verlören ihren Urheber.
     *
     * Das ist vertretbar, weil die Lücke aus Bug #520 an AI Brains Push-Gate
     * hing, nicht an der Peer-Zuschreibung. Umstellung, sobald alle Produkte das
     * neue Paket haben — dieselbe Welle wie Task #3736.
     */
    public static function signPeerActingUser(string $email, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $email, $secret);
    }

    /**
     * Acting-User-Header für Peer-Aufrufe (Produkt → Produkt).
     *
     * @return array<string, string>
     */
    public function peerActingUserHeaders(?string $connectionSecret = null): array
    {
        if (($email = $this->actingUserEmail()) === null) {
            return [];
        }

        $headers = [McpClient::ACTING_USER_HEADER => $email];

        // Vorrang hat das Geheimnis der Peer-VERBINDUNG (#540). Das Event-Secret
        // der Brain-Anbindung bleibt nur der Rückfallweg für Verbindungen, die
        // noch keines haben — seit AI Brain es pro Produkt vergibt, teilen zwei
        // Peers dort ohnehin nichts mehr.
        $secret = ($connectionSecret !== null && $connectionSecret !== '')
            ? $connectionSecret
            : $this->actingSignatureSecret();

        if ($secret !== null) {
            $headers[McpClient::ACTING_SIG_HEADER] = self::signPeerActingUser($email, $secret);
        }

        return $headers;
    }

    /**
     * @param  string|null  $behauptet  Ausdruecklich benannte handelnde Person.
     *                                  Noetig, wo NIEMAND angemeldet ist: Ein
     *                                  Kundenformular oder ein Hintergrund-Lauf
     *                                  hat keine Sitzung, aber sehr wohl einen
     *                                  Menschen, dem der Vorgang gehoert. Ohne
     *                                  das landet er beim Dienst — und damit
     *                                  bei niemandem (AI Brain #5243).
     * @param  string|null  $channel  Woher der Vorgang kam, fuer die Zuordnung
     *                                im Protokoll.
     * @return array<string, string>
     */
    public function actingUserHeaders(?string $behauptet = null, ?string $channel = null): array
    {
        $email = $behauptet !== null && trim($behauptet) !== ''
            ? trim($behauptet)
            : $this->actingUserEmail();

        if ($email === null) {
            return [];
        }

        $headers = [McpClient::ACTING_USER_HEADER => $email];

        if (($secret = $this->actingSignatureSecret()) !== null) {
            $headers[McpClient::ACTING_SIG_HEADER] = self::signActingUser($email, $secret);
        }

        if ($channel !== null && trim($channel) !== '') {
            $headers[ResolveAiBrainActingUser::CHANNEL_HEADER] = trim($channel);
        }

        return $headers;
    }

    /**
     * Ruft ein AI-Brain-MCP-Tool auf (z.B. create-task-tool, list-projects-tool).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $tool, array $arguments = []): array
    {
        return $this->brain()->callTool($tool, $arguments);
    }

    /**
     * Einen Vorgang bei einem ANDEREN System ausloesen — ueber Brain (#5243).
     *
     *     AiBrain::gateway('tasks.create', ['title' => 'Ticket 5']);
     *
     * Ersetzt die Peer-Schiene: Man nennt einen Vorgang, kein Zielsystem. Wer
     * ihn bedient, weiss das Faehigkeits-Register in Brain.
     *
     * @param  array<string, mixed>  $arguments
     * @param  string|null  $product  Nur, wenn ausdruecklich EIN System gemeint ist.
     * @return array{ok: bool, data: array<mixed>|null, text: string|null, error: string|null, message: string|null, product: string|null}
     */
    public function gateway(
        string $capability,
        array $arguments = [],
        ?string $product = null,
        ?string $actingAs = null,
        ?string $channel = null,
    ): array {
        return (new GatewayClient(
            $this->config,
            $this->tokens,
            fn (): array => $this->actingUserHeaders($actingAs, $channel),
        ))->call($capability, $arguments, $product);
    }

    public function brain(): McpClient
    {
        $url = $this->config['mcp']['brain_url']
            ?: rtrim((string) $this->config['base_url'], '/').'/mcp/brain';

        return new McpClient($url, $this->tokens, (int) ($this->config['mcp']['timeout'] ?? 30), fn (): array => $this->actingUserHeaders());
    }

    /**
     * MCP-Client für eine beliebige URL (z.B. ein Produkt-MCP-Server).
     */
    public function mcp(string $url): McpClient
    {
        return new McpClient($url, $this->tokens, (int) ($this->config['mcp']['timeout'] ?? 30), fn (): array => $this->actingUserHeaders());
    }

    // ── Channels (Spezial-MCP) ───────────────────────────────────────────

    /**
     * Channel-Client für einen Channel. Läuft über den einheitlichen MCP-Weg
     * (/mcp/brain): AiBrain::channel('price-research')->message([...]).
     */
    public function channel(string $channel): ChannelClient
    {
        return new ChannelClient($this->brain(), $channel);
    }

    /**
     * Verfügbare (invokable) Channels — Discovery via channel-list-tool.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return (array) ($this->call('channel-list-tool')['channels'] ?? []);
    }

    // ── Events (Schiene 2) ───────────────────────────────────────────────

    /**
     * Event an AI Brain publizieren (signiert, idempotent, retried).
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $type, array $payload, ?string $entityRef = null, ?string $correlationId = null): bool
    {
        return $this->publisher->publish(new BridgeEvent(
            type: $type,
            payload: $payload,
            source: (string) $this->config['source'],
            entityRef: $entityRef,
            correlationId: $correlationId,
        ));
    }

    /**
     * Komfort: auf einen bestimmten eingehenden Event-Typ reagieren.
     * (Alternativ ganz normal auf AiBrainEventReceived lauschen.)
     */
    public function on(string $type, callable $handler): void
    {
        Event::listen(AiBrainEventReceived::class, function (AiBrainEventReceived $e) use ($type, $handler): void {
            if ($e->event->type === $type) {
                $handler($e->event);
            }
        });
    }
}
