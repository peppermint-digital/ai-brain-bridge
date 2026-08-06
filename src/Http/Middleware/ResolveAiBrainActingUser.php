<?php

namespace Peppermint\AiBrainBridge\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gegenstück zu {@see \Peppermint\AiBrainBridge\Mcp\McpClient} — dieselbe
 * Acting-User-Delegation, nur eingehend (AI Brain ruft unseren MCP-Server auf).
 *
 * Ausgehend war das seit Connector-Phase 4.1/4.2 im Paket; eingehend hat es
 * jeder Produkt-Repo selbst gebaut, weshalb die Identität dort entweder fehlte
 * oder aus der Token-Introspection kam — also der TOKEN-BESITZER war, nicht der
 * Mensch, der die Nachricht geschrieben hat. Bei einem Channel-Token ist das
 * immer dieselbe Person, egal wer im Channel tippt (AI-Brain-Bug #471).
 *
 * Einsatz: hinter die bestehende MCP-Auth hängen, z.B.
 *
 *     Route::middleware(['mcp.auth', 'ai-brain.acting-user'])->…
 *
 * Zuschreibung, KEINE Autorisierung: Unbekannte E-Mail oder ungültige Signatur
 * verwerfen den Header, blockieren den Aufruf aber nicht. Ein Abweisen würde
 * laufende Channels stilllegen, sobald irgendwo ein Mapping fehlt. Wer darf was,
 * entscheidet weiterhin die jeweilige Fachlogik.
 */
class ResolveAiBrainActingUser
{
    /** Pro Nachricht behaupteter Schreiber + HMAC darüber. */
    public const ACTING_USER_HEADER = 'X-AI-Brain-Acting-User';

    public const ACTING_SIG_HEADER = 'X-AI-Brain-Acting-Sig';

    /**
     * Herkunft des Aufrufs (AI-Brain-Channel). Rein für die ZUSCHREIBUNG —
     * beantwortet im Audit „über welchen Weg kam das?", auch wenn kein Mensch
     * dahinterstand (Automatisierung, geplanter Task).
     *
     * Bewusst KEIN Autorisierungs-Input: Würde ein Produkt daraus Rechte
     * ableiten, müsste die Channel-Verwaltung in jedem Produkt nachgebaut und
     * gepflegt werden. Diese Entscheidung gehört nach AI Brain, wo die Channels
     * ohnehin verwaltet werden.
     */
    public const CHANNEL_HEADER = 'X-AI-Brain-Channel';

    /** Request-Attribut, unter dem die Herkunft für die App bereitliegt. */
    public const CHANNEL_ATTRIBUTE = 'ai_brain_channel';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (($channel = trim((string) $request->header(self::CHANNEL_HEADER, ''))) !== '') {
            $request->attributes->set(self::CHANNEL_ATTRIBUTE, $channel);
        }

        $asserted = trim((string) $request->header(self::ACTING_USER_HEADER, ''));

        if ($asserted !== '' && $this->signatureValid($request, $asserted)) {
            $user = AiBrain::resolveInboundUser($asserted);

            if ($user instanceof Authenticatable) {
                Auth::setUser($user);
                $request->setUserResolver(fn () => $user);
            } elseif ($user !== null) {
                // Resolver liefert etwas Unbrauchbares: NICHT durchreichen —
                // Auth::setUser() würde eine TypeError werfen und den ganzen
                // MCP-Aufruf mit 500 beenden. Zuschreibung ist es nicht wert,
                // dafür einen Channel lahmzulegen.
                Log::warning('AI-Brain-Acting-User: Resolver lieferte kein Authenticatable', [
                    'email' => $asserted,
                    'type' => get_debug_type($user),
                ]);
            } else {
                Log::info('AI-Brain-Acting-User ohne lokales Konto', ['email' => $asserted]);
            }
        }

        return $next($request);
    }

    /**
     * HMAC über die behauptete E-Mail mit dem geteilten Komm-Layer-Secret —
     * exakt das Secret, mit dem der McpClient ausgehend signiert.
     *
     * Ohne konfiguriertes Secret gibt es nichts zu prüfen; dann trägt allein das
     * mcp:use-Token die Authentifizierung. Dieselbe fail-safe-Doktrin wie in AI
     * Brains SetMcpActingUser — frische Installationen funktionieren ohne
     * Secret-Verteilung, verlieren aber die zusätzliche Absicherung.
     */
    protected function signatureValid(Request $request, string $assertedEmail): bool
    {
        $secret = $this->signingSecret($request);

        if ($secret === null || $secret === '') {
            return true;
        }

        $provided = $request->header(self::ACTING_SIG_HEADER);
        $expected = 'sha256='.hash_hmac('sha256', $assertedEmail, $secret);

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            // „Keine Signatur mitgeschickt" und „Signatur passt nicht" sind zwei
            // verschiedene Fehler mit zwei verschiedenen Ursachen — sie in
            // dieselbe Zeile zu schreiben hat bei #540 eine Stunde gekostet.
            // Vom HMAC nur der Anfang: genug zum Vergleichen zweier Logs, zu
            // wenig zum Rueckrechnen.
            Log::warning('AI-Brain-Acting-User: Signatur verworfen, Header ignoriert', [
                'email' => $assertedEmail,
                'grund' => is_string($provided) ? 'Signatur passt nicht' : 'keine Signatur mitgeschickt',
                'erwartet' => substr($expected, 0, 18),
                'erhalten' => is_string($provided) ? substr($provided, 0, 18) : null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Mit welchem Geheimnis wird die Behauptung geprüft? Für AI-Brain-Aufrufe ist
     * das die Anbindung selbst; die Peer-Variante überschreibt das mit dem
     * Geheimnis der jeweiligen Verbindung ({@see ResolvePeerActingUser}).
     */
    protected function signingSecret(Request $request): ?string
    {
        return AiBrain::actingSecret();
    }
}
