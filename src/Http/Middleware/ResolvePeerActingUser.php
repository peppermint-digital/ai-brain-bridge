<?php

namespace Peppermint\AiBrainBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gegenstück zu {@see \Peppermint\AiBrainBridge\Peer\PeerClient} — dieselbe
 * Acting-User-Delegation wie bei AI Brain, nur zwischen zwei Produkten.
 *
 * Hintergrund: Eine Produkt-Verbindung ist bewusst KEINE Person (siehe
 * `is_peer` in den Produkten). Damit entstanden beim aufgerufenen Produkt aber
 * Datensätze ohne Urheber — ehrlicher als der frühere Zustand (sie trugen den
 * Namen dessen, der die Verbindung eingerichtet hatte), aber immer noch eine
 * Lücke. Das aufrufende Produkt weiß, wer gehandelt hat, und sagt es pro
 * Anfrage — signiert mit dem geteilten Event-Secret.
 *
 * Der Unterschied zu {@see ResolveAiBrainActingUser}: Der Header wird NUR
 * beachtet, wenn der Aufruf noch zu keiner Person gehört. Ein persönlicher
 * Token behält seine Identität und kann sich nicht per Header umschreiben —
 * sonst wäre die Trennung zwischen Systemverbindung und Person wieder
 * aufgehoben, nur an anderer Stelle.
 *
 * Einsatz: hinter die bestehende API-Authentifizierung hängen, z.B.
 *
 *     Route::middleware(['api.token', 'peer.acting-user'])->…
 *
 * Zuschreibung, KEINE Autorisierung — wie beim Brain-Pendant: eine unbekannte
 * E-Mail oder eine falsche Signatur verwerfen den Header, blockieren den Aufruf
 * aber nicht.
 */
class ResolvePeerActingUser extends ResolveAiBrainActingUser
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Der Aufruf hat bereits eine Person (persönlicher Token): Herkunft
        // notieren, Identität nicht anfassen.
        if ($request->user() !== null) {
            $this->rememberChannel($request);

            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * Geprüft wird mit dem Geheimnis DIESER Verbindung (#540) — auffindbar über
     * den Token, mit dem der Peer gerade anruft.
     *
     * Der Rückfall auf das Brain-Event-Secret bleibt für Verbindungen, die noch
     * keines hinterlegt haben (altes Paket auf der Gegenseite). Er trägt nur
     * noch, solange beide Produkte dasselbe Event-Secret haben — seit AI Brain
     * es pro Produkt vergibt, ist das der Ausnahmefall, nicht die Regel.
     */
    protected function signingSecret(Request $request): ?string
    {
        $connector = app(\Peppermint\AiBrainBridge\Peer\PeerConnectionManager::class)
            ->findInboundConnector($request->bearerToken());

        if ($connector !== null && $connector->hasActingSecret()) {
            return $connector->acting_secret;
        }

        return parent::signingSecret($request);
    }

    /**
     * Die Herkunftsnotiz (Channel) ist reine Zuschreibung und deshalb auch für
     * personengebundene Aufrufe sinnvoll.
     */
    protected function rememberChannel(Request $request): void
    {
        if (($channel = trim((string) $request->header(self::CHANNEL_HEADER, ''))) !== '') {
            $request->attributes->set(self::CHANNEL_ATTRIBUTE, $channel);
        }
    }
}
