<?php

namespace Peppermint\AiBrainBridge\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Events\AiBrainEventReceived;

/**
 * Ein entzogener Zugang beendet die laufende Sitzung (AI Brain #5343).
 *
 * AI Brain meldet, dass jemand dieses System nicht mehr benutzen darf. Beenden
 * muss die Sitzung dieses Produkt selbst — nur es weiss, wie seine Sitzungen
 * gespeichert sind und unter welcher Kennung die Person hier gefuehrt wird.
 *
 * ## Wer gemeint ist
 *
 * Dieselbe Aufloesung wie bei der Anmeldung (`BrainLogin::resolve`). Ein Produkt
 * mit abweichender Zuordnung ueberschreibt sie einmal und beides folgt
 * derselben Regel — sonst koennte jemand ueber den einen Weg hereinkommen und
 * ueber den anderen nicht hinausgeworfen werden.
 *
 * ## Warum ueber die Sitzungstabelle
 *
 * Laravel kann nur die EIGENE Sitzung beenden, nicht die einer anderen Person.
 * Mit `SESSION_DRIVER=database` stehen sie aber in einer Tabelle mit `user_id`
 * — dort loeschen wirkt sofort und fuer alle Geraete dieser Person.
 *
 * Bei einem anderen Treiber (`file`, `cookie`) geht das nicht; dann wird das
 * ausdruecklich protokolliert, statt so zu tun, als sei es erledigt. Ein
 * Sicherheitsversprechen, das still ins Leere laeuft, ist schlimmer als ein
 * eingestandenes.
 */
class SitzungenBeenden
{
    /** Der Zugang wurde entzogen — diese Person darf hier nicht mehr herein. */
    public const EREIGNIS = 'user.access.revoked';

    /**
     * Die Person hat sich abgemeldet (AI Brain #5344). Sie darf weiterhin
     * herein, nur nicht mehr mit dieser Sitzung.
     *
     * Zwei Typen mit derselben Wirkung, absichtlich: Ein Produkt, das eines
     * Tages auf einen Entzug anders reagieren will als auf eine Abmeldung,
     * kann sie unterscheiden.
     */
    public const ABMELDUNG = 'user.logged_out';

    public function handle(AiBrainEventReceived $event): void
    {
        if (! in_array($event->event->type, [self::EREIGNIS, self::ABMELDUNG], true)) {
            return;
        }

        $email = $event->event->payload['email'] ?? null;

        if (! is_string($email) || $email === '') {
            Log::warning('Zugangsentzug ohne Kennung — nichts zu tun');

            return;
        }

        $user = BrainLogin::resolve(['email' => $email]);

        if (! $user instanceof Authenticatable) {
            // Kein Konto hier: nichts zu beenden. Kein Fehler.
            Log::info('Zugangsentzug: diese Person hat hier kein Konto', ['email' => $email]);

            return;
        }

        $this->beenden($user, $email, $event->event->type);
    }

    protected function beenden(Authenticatable $user, string $email, string $typ): void
    {
        if (! Schema::hasTable('sessions')) {
            Log::warning(
                'Zugangsentzug NICHT durchgesetzt: Sitzungen liegen nicht in der Datenbank. '
                .'Mit SESSION_DRIVER=file oder cookie laesst sich eine fremde Sitzung nicht beenden — '
                .'die Person bleibt bis zum Ablauf angemeldet.',
                ['email' => $email, 'typ' => $typ]
            );

            return;
        }

        $anzahl = DB::table('sessions')->where('user_id', $user->getAuthIdentifier())->delete();

        Log::info($typ === self::ABMELDUNG ? 'Abmeldung weitergereicht' : 'Zugangsentzug durchgesetzt', [
            'email' => $email,
            'beendete_sitzungen' => $anzahl,
        ]);
    }
}
