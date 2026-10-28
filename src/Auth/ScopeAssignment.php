<?php

namespace Peppermint\AiBrainBridge\Auth;

/**
 * Die Zuständigkeit einer Person in DIESEM Produkt setzen (AI Brain #9007).
 *
 * Gegenstück zu {@see ScopeCatalog}: Der meldet, welche Ebenen und Werte es
 * gibt, dieser setzt sie. Gebaut wie {@see RoleAssignment}, mit einem
 * Unterschied — es gibt keine Vorgabe über ein geteiltes Rechte-Paket. Wer
 * Zuständigkeiten hat, hat sie in eigenen Tabellen und setzt sie selbst:
 *
 *     ScopeAssignment::handleUsing(function (string $email, string $ebene, array $ids, ?bool $alle) {
 *         …
 *         return ['ok' => true, 'message' => 'Bereiche gesetzt.', 'zugewiesen' => […]];
 *     });
 *
 * ## Ersetzen, nicht hinzufügen
 *
 * Ein Aufruf trägt die VOLLSTÄNDIGE Liste für eine Ebene — so wie `sync()` im
 * Produkt. Es gibt bewusst kein „zuweisen/entziehen" wie bei Rollen: Dort geht
 * es um eine einzelne Rolle, hier um eine Menge, und zwei Modi auf einer Menge
 * sind die Art von Schnittstelle, bei der am Ende niemand mehr weiß, ob eine
 * leere Liste „nichts ändern" oder „alles weg" heißt. Eine leere Liste heißt
 * hier: nichts mehr zugewiesen.
 *
 * ## Die Riegel bleiben im Produkt
 *
 * Genau wie bei den Rollen. Nur das Produkt weiß, dass ein Bereich zu einem
 * zugewiesenen Mandanten gehören muss, dass ein stillgelegter Mandant nicht
 * neu vergeben wird und was passiert, wenn jemand ohne jede Zuständigkeit
 * dasteht. AI Brain weiß das alles nur aus zweiter Hand.
 */
class ScopeAssignment
{
    /** @var (callable(string, string, list<string>, ?bool): array<string, mixed>)|null */
    protected static $handler = null;

    /**
     * @param  (callable(string, string, list<string>, ?bool): array<string, mixed>)|null  $handler
     */
    public static function handleUsing(?callable $handler): void
    {
        static::$handler = $handler;
    }

    public static function moeglich(): bool
    {
        return static::$handler !== null;
    }

    /**
     * @param  list<string>  $ids
     * @return array{ok: bool, message: string, zugewiesen: array<string, list<string>>, alle: array<string, bool>}
     */
    public static function setzen(string $email, string $ebene, array $ids, ?bool $alle = null): array
    {
        $email = trim($email);
        $ebene = trim($ebene);

        if ($email === '') {
            return self::fehler('Ohne E-Mail-Adresse ist nicht klar, wessen Zuständigkeit gesetzt werden soll.');
        }

        if ($ebene === '') {
            return self::fehler('Ohne Ebene ist nicht klar, was gesetzt werden soll. Die Ebenen meldet list-scopes-tool.');
        }

        if (static::$handler === null) {
            return self::fehler('Dieses System verwaltet keine Zuständigkeiten. Es hat keinen Handler hinterlegt — dann gibt es hier auch nichts zu setzen.');
        }

        $bekannt = array_column(ScopeCatalog::ebenen(), 'schluessel');

        if ($bekannt !== [] && ! in_array($ebene, $bekannt, true)) {
            return self::fehler("Die Ebene '{$ebene}' gibt es hier nicht. Bekannt sind: ".implode(', ', $bekannt).'.');
        }

        $ergebnis = (array) (static::$handler)(
            $email,
            $ebene,
            array_values(array_map('strval', $ids)),
            $alle,
        );

        return [
            'ok' => (bool) ($ergebnis['ok'] ?? false),
            'message' => (string) ($ergebnis['message'] ?? ''),
            'zugewiesen' => (array) ($ergebnis['zugewiesen'] ?? []),
            'alle' => (array) ($ergebnis['alle'] ?? []),
        ];
    }

    /**
     * @return array{ok: bool, message: string, zugewiesen: array<string, list<string>>, alle: array<string, bool>}
     */
    protected static function fehler(string $meldung): array
    {
        return ['ok' => false, 'message' => $meldung, 'zugewiesen' => [], 'alle' => []];
    }
}
