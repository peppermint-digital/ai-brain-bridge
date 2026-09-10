<?php

namespace Peppermint\AiBrainBridge\Auth;

/**
 * Welche Rollen es in diesem Produkt gibt — und was sie bedeuten (AI Brain #5245).
 *
 * ## Warum das gemeldet und nicht gepflegt wird
 *
 * Eine Rollenliste, die in der Kontrollebene von Hand geführt wird, ist am Tag
 * ihrer Einführung korrekt und danach nie wieder: Wer im Produkt eine Rolle
 * anlegt, denkt nicht daran, sie anderswo nachzutragen. Deshalb fragt AI Brain
 * hier nach, statt mitzuschreiben — dasselbe Muster wie beim
 * Fähigkeiten-Register.
 *
 * ## Was ZENTRAL ist und was LOKAL bleibt
 *
 * Zentral ist die **Zuordnung** („diese Person gehört zur Buchhaltung"), lokal
 * die **Wirkung**. Die Rolle `buchhaltung` heißt in der Verwaltung „liest
 * Belege" und im Manager „darf Stundenlisten ziehen" — derselbe Name, zwei
 * Bedeutungen. Ein produktübergreifend vereinheitlichter Rollensatz wäre
 * deshalb überall ein bisschen falsch.
 *
 * ## Vorgabe und Abweichung
 *
 * Ohne Zutun liest der Katalog die Rollen von `spatie/laravel-permission` —
 * das deckt die Produkte ab, die es benutzt. Wessen Rollen woanders liegen
 * (eine `role`-Spalte, eine Konstantenliste), meldet sie selbst:
 *
 *     RoleCatalog::resolveUsing(fn () => collect(User::ROLES)
 *         ->map(fn ($label, $name) => [
 *             'name' => $name,
 *             'label' => $label,
 *             'permissions' => [],
 *         ])->values()->all());
 */
class RoleCatalog
{
    /** @var (callable(): array<int, array<string, mixed>>)|null */
    protected static $resolver = null;

    /**
     * @param  (callable(): array<int, array<string, mixed>>)|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * @return list<array{name: string, label: string, permissions: list<string>, users: int|null}>
     */
    public static function alle(): array
    {
        $roh = static::$resolver !== null
            ? (static::$resolver)()
            : static::ausSpatie();

        return array_values(array_map(static::normalisieren(...), $roh));
    }

    /**
     * @param  array<string, mixed>  $rolle
     * @return array{name: string, label: string, permissions: list<string>, users: int|null}
     */
    protected static function normalisieren(array $rolle): array
    {
        $name = (string) ($rolle['name'] ?? '');

        return [
            'name' => $name,
            // Ohne Klartext-Bezeichnung steht in der Kontrollebene ein
            // technischer Name, den dort niemand einordnen kann. Der Name ist
            // die schlechteste, aber ehrlichste Vorgabe.
            'label' => (string) ($rolle['label'] ?? $name),
            'permissions' => array_values(array_map('strval', (array) ($rolle['permissions'] ?? []))),
            // Wie viele Menschen die Rolle tragen — die Zahl beantwortet
            // „wird das überhaupt benutzt", ohne dass jemand nachzählt.
            'users' => isset($rolle['users']) ? (int) $rolle['users'] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function ausSpatie(): array
    {
        $klasse = '\Spatie\Permission\Models\Role';

        if (! class_exists($klasse)) {
            return [];
        }

        return $klasse::query()
            ->with('permissions:id,name')
            // OHNE die globalen Filter des Nutzer-Modells zaehlen. Wer eine
            // Rolle traegt, ist eine andere Frage als wer in Auswahllisten
            // erscheinen soll: Der Manager blendet Dienst-Konten (z. B. die
            // Buchhaltung) ueberall aus — und meldete damit „0 Traeger" fuer
            // eine Rolle, die jemand hat. Eine Null, die „niemand" behauptet,
            // waere schlimmer als gar keine Zahl (AI Brain #5245).
            ->withCount(['users' => fn ($abfrage) => $abfrage->withoutGlobalScopes()])
            ->orderBy('name')
            ->get()
            ->map(fn ($rolle) => [
                'name' => $rolle->name,
                'label' => $rolle->name,
                'permissions' => $rolle->permissions->pluck('name')->all(),
                'users' => $rolle->users_count,
            ])
            ->all();
    }
}
