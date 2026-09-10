<?php

namespace Peppermint\AiBrainBridge\Auth;

/**
 * Eine Rolle in DIESEM Produkt vergeben oder entziehen (AI Brain #5314).
 *
 * Das Gegenstück zu {@see RoleCatalog}: Der meldet, welche Rollen es gibt,
 * dieser setzt sie. Zusammen ergeben sie das, was eine Kontrollebene ausmacht —
 * eine Stelle, an der man sieht UND entscheidet, wer wo hineinkommt.
 *
 * ## Was hier ausdrücklich NICHT passiert
 *
 * **Rollen werden nie angelegt.** Ein unbekannter Name ist ein Fehler, keine
 * Einladung. Wer aus der Ferne Rollen erfinden könnte, könnte sich auch eine
 * bauen, die alles darf — und niemand im Produkt hätte je entschieden, dass es
 * sie gibt.
 *
 * **Konten werden nie angelegt.** Eine Adresse ohne Konto ist ein Fehler. Sonst
 * entstünden Karteileichen, sobald sich jemand vertippt.
 *
 * **Die letzte Person einer geschützten Rolle wird nicht entfernt.** Wer den
 * letzten Administrator entfernt, sperrt alle aus — und es gibt dann niemanden
 * mehr, der es rückgängig machen kann. Dieselbe Überlegung wie beim
 * Notausgang, den die Verwaltung am 10.09.2026 bekommen hat.
 *
 * ## Der Schalter ist die Registrierung
 *
 * Ein Produkt, das seine Rollen nicht aus der Ferne ändern lassen will,
 * registriert {@see \Peppermint\AiBrainBridge\Mcp\Tools\AssignRoleTool} einfach
 * nicht. Es braucht keinen zusätzlichen Schalter, den jemand pflegen muss —
 * und keine Umgebungsvariable.
 */
class RoleAssignment
{
    public const MODUS_ZUWEISEN = 'zuweisen';

    public const MODUS_ENTZIEHEN = 'entziehen';

    /** @var (callable(string, string, string): array<string, mixed>)|null */
    protected static $handler = null;

    /** @var list<string> */
    protected static array $geschuetzt = ['admin', 'administrator'];

    /**
     * Produkte, deren Rollen nicht bei spatie/laravel-permission liegen,
     * setzen sie selbst.
     *
     * @param  (callable(string, string, string): array<string, mixed>)|null  $handler
     */
    public static function handleUsing(?callable $handler): void
    {
        static::$handler = $handler;
    }

    /**
     * Welche Rollen nicht bis auf null geleert werden dürfen.
     *
     * @param  list<string>  $namen
     */
    public static function schuetze(array $namen): void
    {
        static::$geschuetzt = array_values(array_map('strtolower', $namen));
    }

    /**
     * @return list<string>
     */
    public static function geschuetzte(): array
    {
        return static::$geschuetzt;
    }

    /**
     * @return array{ok: bool, message: string, rollen: list<string>}
     */
    public static function setzen(string $email, string $rolle, string $modus): array
    {
        $email = trim($email);
        $rolle = trim($rolle);

        if (! in_array($modus, [self::MODUS_ZUWEISEN, self::MODUS_ENTZIEHEN], true)) {
            return self::fehler("Unbekannter Modus '{$modus}'. Erlaubt sind 'zuweisen' und 'entziehen'.");
        }

        if ($email === '' || $rolle === '') {
            return self::fehler('E-Mail und Rolle dürfen nicht leer sein.');
        }

        if (static::$handler !== null) {
            return (static::$handler)($email, $rolle, $modus);
        }

        return static::ueberSpatie($email, $rolle, $modus);
    }

    /**
     * @return array{ok: bool, message: string, rollen: list<string>}
     */
    protected static function ueberSpatie(string $email, string $rolle, string $modus): array
    {
        $rollenKlasse = '\Spatie\Permission\Models\Role';

        if (! class_exists($rollenKlasse)) {
            return self::fehler('Dieses System verwaltet seine Rollen nicht über spatie/laravel-permission. Es muss einen eigenen Handler hinterlegen.');
        }

        $nutzerKlasse = config('auth.providers.users.model');

        // OHNE die globalen Filter suchen: Der Manager blendet Dienst-Konten
        // aus Auswahllisten aus. Ein Konto, das dort nicht auftaucht, ist
        // trotzdem eines — es "nicht gefunden" zu nennen waere gelogen.
        $nutzer = $nutzerKlasse::query()->withoutGlobalScopes()->where('email', $email)->first();

        if (! $nutzer) {
            return self::fehler("Kein Konto mit der Adresse '{$email}'. Rollen werden hier vergeben, Konten nicht angelegt.");
        }

        $vorhanden = $rollenKlasse::query()->where('name', $rolle)->first();

        if (! $vorhanden) {
            $bekannte = $rollenKlasse::query()->orderBy('name')->pluck('name')->implode(', ');

            return self::fehler("Die Rolle '{$rolle}' gibt es hier nicht. Bekannt sind: {$bekannte}.");
        }

        if ($modus === self::MODUS_ENTZIEHEN) {
            if (! $nutzer->hasRole($rolle)) {
                // Kein Fehler: Der gewuenschte Zustand ist bereits erreicht.
                return self::erfolg($nutzer, "'{$email}' trug die Rolle '{$rolle}' ohnehin nicht.");
            }

            if (static::waereDerLetzte($vorhanden, $nutzer)) {
                return self::fehler("'{$email}' ist die letzte Person mit der Rolle '{$rolle}'. Diese Rolle darf nicht leer werden — sonst kann niemand mehr etwas zurücknehmen.");
            }

            $nutzer->removeRole($rolle);

            return self::erfolg($nutzer->fresh(), "'{$rolle}' entzogen.");
        }

        if ($nutzer->hasRole($rolle)) {
            return self::erfolg($nutzer, "'{$email}' hatte die Rolle '{$rolle}' bereits.");
        }

        $nutzer->assignRole($rolle);

        return self::erfolg($nutzer->fresh(), "'{$rolle}' zugewiesen.");
    }

    /**
     * Ist diese Person die letzte Trägerin einer geschützten Rolle?
     */
    protected static function waereDerLetzte(mixed $rolle, mixed $nutzer): bool
    {
        if (! in_array(strtolower((string) $rolle->name), static::$geschuetzt, true)) {
            return false;
        }

        // Wieder ohne globale Filter zaehlen — aus demselben Grund wie oben.
        // Wer hier zu WENIG zaehlt, entfernt den vermeintlich letzten
        // Administrator und sperrt alle aus.
        $traeger = $rolle->users()->withoutGlobalScopes()->count();

        return $traeger <= 1;
    }

    /**
     * @return array{ok: bool, message: string, rollen: list<string>}
     */
    protected static function erfolg(mixed $nutzer, string $meldung): array
    {
        return [
            'ok' => true,
            'message' => $meldung,
            'rollen' => $nutzer->getRoleNames()->values()->all(),
        ];
    }

    /**
     * @return array{ok: bool, message: string, rollen: list<string>}
     */
    protected static function fehler(string $meldung): array
    {
        return ['ok' => false, 'message' => $meldung, 'rollen' => []];
    }
}
