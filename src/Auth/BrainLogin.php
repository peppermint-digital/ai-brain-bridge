<?php

namespace Peppermint\AiBrainBridge\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Wer aus AI Brain herüberkommt, ist hier drin welche Person? (AI Brain #5266)
 *
 * AI Brain beantwortet die Frage „wer bist du" — aber nicht die Frage „wer bist
 * du HIER". Das kann nur das Produkt: Es kennt sein Nutzer-Modell, seine Gäste,
 * seine Stillgelegten.
 *
 * Der Vorgabe-Weg sucht über die E-Mail und lässt nur herein, wen es schon
 * gibt. Das ist Absicht: Eine Anmeldung darf kein Konto erzeugen. Wer neu
 * dazukommt, wird im Verzeichnis freigeschaltet — dabei legt AI Brain das
 * Konto über `create-user-tool` an. Anders herum entstünden bei jedem
 * Tippfehler in einer Freigabe Karteileichen.
 *
 * Ein Produkt mit abweichender Zuordnung überschreibt den Weg:
 *
 *     BrainLogin::resolveUsing(fn (array $person) => Mitarbeiter::query()
 *         ->where('brain_id', $person['id'])->first());
 */
class BrainLogin
{
    /** @var (callable(array<string, mixed>): (Authenticatable|null))|null */
    protected static $resolver = null;

    /**
     * @param  (callable(array<string, mixed>): (Authenticatable|null))|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * @param  array<string, mixed>  $person  Antwort von /api/v1/me (id, name, email)
     */
    public static function resolve(array $person): ?Authenticatable
    {
        if (static::$resolver !== null) {
            return (static::$resolver)($person);
        }

        return static::vorgabe($person);
    }

    /**
     * Über die E-Mail im Nutzer-Modell des eingestellten Guards suchen.
     */
    protected static function vorgabe(array $person): ?Authenticatable
    {
        $email = isset($person['email']) ? trim((string) $person['email']) : '';

        if ($email === '') {
            return null;
        }

        $guard = (string) config('ai-brain-bridge.login.guard', 'web');
        $provider = Auth::guard($guard)->getProvider();

        if (! method_exists($provider, 'retrieveByCredentials')) {
            return null;
        }

        return $provider->retrieveByCredentials(['email' => $email]);
    }

    /**
     * Darf diese Person sich hier anmelden?
     *
     * Produkte, die den Entzug kennen (`is_active`), setzen ihn damit auch für
     * den gemeinsamen Anmeldeweg durch. Ohne die Prüfung wäre AI Brain eine
     * Hintertür an der eigenen Sperre vorbei.
     */
    public static function darfAnmelden(Authenticatable $user): bool
    {
        foreach (['is_active', 'active'] as $feld) {
            if (isset($user->{$feld}) || array_key_exists($feld, (array) $user->getAttributes())) {
                return (bool) $user->{$feld};
            }
        }

        return true;
    }
}
