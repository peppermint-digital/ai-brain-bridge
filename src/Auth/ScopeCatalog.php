<?php

namespace Peppermint\AiBrainBridge\Auth;

/**
 * Welche Zuständigkeiten es in diesem Produkt gibt (AI Brain #9007).
 *
 * Das dritte Stück neben {@see RoleCatalog} und {@see RoleAssignment}. Eine
 * Rolle sagt, WAS jemand darf; eine Zuständigkeit sagt, WORAN. Im CRM sind das
 * Mandant (Marke) und darunter der Bereich — zwei Zuweisungen, die über die
 * Sichtbarkeit jedes Vorgangs entscheiden und bis hierher nur in der Maske des
 * Produkts zu setzen waren.
 *
 * ## Warum gemeldet und nicht vereinheitlicht
 *
 * Dieselbe Begründung wie beim Rollen-Register, nur schärfer: „Bereich" heißt
 * im CRM ein Bundesland unter einer Marke und im Manager gar nichts. Ein
 * produktübergreifend festgelegter Satz von Ebenen wäre überall ein bisschen
 * falsch. Deshalb gibt das Paket nur die FORM vor — Ebenen, Werte, Eltern — und
 * das Produkt füllt sie mit dem, was bei ihm gilt.
 *
 * Zentral ist die ZUORDNUNG, lokal die WIRKUNG. AI Brain zeigt und setzt, wer
 * wofür zuständig ist; was das für die Sichtbarkeit bedeutet, entscheidet
 * weiterhin das Produkt (globale Scopes, Policies, `accessibleTerritoryIds()`).
 *
 * ## Zwei Ebenen, und die zweite ist OPTIONAL
 *
 * Eine Ebene kann `alle_moeglich` melden: Dann gibt es neben der Auswahl den
 * Zustand „alle" (im CRM `has_all_brands`). Und eine untere Ebene darf leer
 * sein, ohne dass das „nichts erlaubt" heißt — ein Mandant, der keine Bereiche
 * führt, schränkt nicht ein. Wer diese Unterscheidung verliert, sperrt genau
 * die aus, die nie eine Trennung wollten. Siehe das Wiki „Optionale zweite
 * Scope-Ebene: drei Zustände, nicht zwei".
 *
 * ## Ohne Resolver gibt es diese Fähigkeit nicht
 *
 * Es gibt KEINE Vorgabe wie bei spatie/laravel-permission — Zuständigkeiten
 * sind nichts, was ein geteiltes Paket erraten könnte. Ein Produkt ohne
 * Resolver meldet eine leere Ebenenliste, und AI Brain zeigt dann auch nichts
 * an. Der Schalter ist die Registrierung:
 *
 *     ScopeCatalog::resolveUsing(fn () => [[
 *         'schluessel' => 'mandant',
 *         'beschriftung' => 'Mandant',
 *         'alle_moeglich' => true,
 *         'werte' => Brand::active()->get()->map(fn ($m) => [
 *             'id' => (string) $m->id, 'label' => $m->name,
 *         ])->all(),
 *     ]]);
 */
class ScopeCatalog
{
    /** @var (callable(): array<int, array<string, mixed>>)|null */
    protected static $resolver = null;

    /** @var (callable(string): array<string, mixed>)|null */
    protected static $personResolver = null;

    /**
     * @param  (callable(): array<int, array<string, mixed>>)|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Was EINE Person zugewiesen hat. Getrennt vom Katalog, weil der Katalog
     * für alle gilt und das hier für eine — und weil AI Brain beides in
     * derselben Antwort braucht, um die Maske zu füllen.
     *
     * @param  (callable(string): array<string, mixed>)|null  $resolver
     */
    public static function resolvePersonUsing(?callable $resolver): void
    {
        static::$personResolver = $resolver;
    }

    /**
     * Bietet dieses Produkt überhaupt Zuständigkeiten an?
     *
     * Gemessen am hinterlegten Resolver, nicht an einem Schalter: Ein Produkt,
     * das keinen setzt, hat keine — und soll die Fähigkeit auch nicht melden.
     */
    public static function vorhanden(): bool
    {
        return static::$resolver !== null;
    }

    /**
     * @return list<array{schluessel: string, beschriftung: string, mehrfach: bool, alle_moeglich: bool, alle_beschriftung: string|null, eltern: string|null, hinweis: string|null, werte: list<array{id: string, label: string, eltern_id: string|null}>}>
     */
    public static function ebenen(): array
    {
        if (static::$resolver === null) {
            return [];
        }

        return array_values(array_map(
            static::normalisiereEbene(...),
            (array) (static::$resolver)(),
        ));
    }

    /**
     * Die Zuweisung einer Person, Ebenen-Schlüssel => Liste von IDs, plus
     * `alle` je Ebene, die das anbietet.
     *
     * @return array{zugewiesen: array<string, list<string>>, alle: array<string, bool>}
     */
    public static function person(string $email): array
    {
        $leer = ['zugewiesen' => [], 'alle' => []];

        if (static::$personResolver === null) {
            return $leer;
        }

        $roh = (array) (static::$personResolver)($email);

        $zugewiesen = [];

        foreach ((array) ($roh['zugewiesen'] ?? []) as $ebene => $ids) {
            $zugewiesen[(string) $ebene] = array_values(array_map('strval', (array) $ids));
        }

        $alle = [];

        foreach ((array) ($roh['alle'] ?? []) as $ebene => $wert) {
            $alle[(string) $ebene] = (bool) $wert;
        }

        return ['zugewiesen' => $zugewiesen, 'alle' => $alle];
    }

    /**
     * @param  array<string, mixed>  $ebene
     * @return array{schluessel: string, beschriftung: string, mehrfach: bool, alle_moeglich: bool, alle_beschriftung: string|null, eltern: string|null, hinweis: string|null, werte: list<array{id: string, label: string, eltern_id: string|null}>}
     */
    protected static function normalisiereEbene(array $ebene): array
    {
        $schluessel = (string) ($ebene['schluessel'] ?? '');

        return [
            'schluessel' => $schluessel,
            // Ohne Klartext steht in AI Brain ein technischer Name, den dort
            // niemand einordnen kann — derselbe Grund wie beim Rollen-Label.
            'beschriftung' => (string) ($ebene['beschriftung'] ?? $schluessel),
            'mehrfach' => (bool) ($ebene['mehrfach'] ?? true),
            'alle_moeglich' => (bool) ($ebene['alle_moeglich'] ?? false),
            'alle_beschriftung' => isset($ebene['alle_beschriftung'])
                ? (string) $ebene['alle_beschriftung']
                : null,
            // Welche Ebene darüber liegt. Erst damit weiss AI Brain, dass ein
            // Bereich nur unter einem zugewiesenen Mandanten zu haben ist —
            // und kann die Auswahl entsprechend verschachteln, statt sechzehn
            // Bundeslaender ohne Zusammenhang aufzulisten.
            'eltern' => isset($ebene['eltern']) ? (string) $ebene['eltern'] : null,
            'hinweis' => isset($ebene['hinweis']) ? (string) $ebene['hinweis'] : null,
            'werte' => array_values(array_map(
                static fn (array $wert): array => [
                    'id' => (string) ($wert['id'] ?? ''),
                    'label' => (string) ($wert['label'] ?? ($wert['id'] ?? '')),
                    'eltern_id' => isset($wert['eltern_id']) ? (string) $wert['eltern_id'] : null,
                ],
                (array) ($ebene['werte'] ?? []),
            )),
        ];
    }
}
