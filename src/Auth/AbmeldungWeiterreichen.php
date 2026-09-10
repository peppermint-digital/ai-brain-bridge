<?php

namespace Peppermint\AiBrainBridge\Auth;

use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Config\BridgeConfig;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Wer sich hier abmeldet, ist ueberall abgemeldet (AI Brain #5344).
 *
 * Dieses Produkt hat seine eigene Sitzung bereits beendet. Der Rest faellt in
 * AI Brain: die dortige Sitzung und die der uebrigen Systeme.
 *
 * ## Warum ueberall und nicht nur hier
 *
 * „Nur hier abmelden" klingt freundlicher und reisst genau das Loch, das die
 * Funktion schliessen soll: Wer am geteilten Rechner im Manager abmeldet und
 * geht, laesst die Brain-Sitzung offen — und ueber die Umschaltleiste kommt der
 * Naechste ueberall hin. Bei OIDC ist das Weiterreichen der Abmeldung aus
 * demselben Grund der Standard.
 *
 * ## Best-effort, niemals blockierend
 *
 * **Eine Abmeldung darf nie daran scheitern, dass der Hub langsam ist oder
 * schweigt.** Wer auf „Abmelden" klickt, ist danach abgemeldet — das ist die
 * Zusage, und sie gilt lokal auch dann, wenn der Rest nicht durchkommt. Deshalb
 * eine kurze Zeitgrenze und ein weiter Fang: Ein Fehler hier wird
 * protokolliert, nicht weitergeworfen.
 *
 * Die Folge im Fehlerfall ist benannt und nicht verschwiegen: Die Sitzungen in
 * den anderen Systemen bleiben stehen.
 */
class AbmeldungWeiterreichen
{
    public function handle(Logout $ereignis): void
    {
        if (! config('ai-brain-bridge.login.enabled')) {
            return;
        }

        $user = $ereignis->user;

        if ($user === null) {
            return;
        }

        $basis = rtrim((string) config('ai-brain-bridge.base_url'), '/');
        $slug = (string) (BridgeConfig::load()['source'] ?? config('ai-brain-bridge.source'));

        try {
            Http::withToken(app(OAuthTokenProvider::class)->token())
                ->withHeaders(AiBrain::actingUserHeaders())
                ->acceptJson()
                // Kurz: Der Mensch wartet auf die Abmeldung.
                ->timeout((int) config('ai-brain-bridge.login.logout_timeout', 4))
                ->post($basis.'/api/v1/users/me/logout-everywhere', ['von' => $slug]);
        } catch (\Throwable $e) {
            Log::warning(
                'Abmeldung liess sich nicht weiterreichen — die Sitzungen in den anderen '
                .'Systemen bleiben bestehen.',
                ['fehler' => $e->getMessage()]
            );
        }
    }
}
