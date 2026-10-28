<?php

namespace Peppermint\AiBrainBridge\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\AiBrainBridge\Auth\ScopeAssignment;

/**
 * Setzt die Zuständigkeit einer Person in diesem Produkt (AI Brain #9007).
 *
 * SCHREIBEND, und an derselben empfindlichen Stelle wie {@see AssignRoleTool}:
 * Wer den Mandanten wegnimmt, nimmt die Sichtbarkeit aller Vorgänge mit. Die
 * Riegel liegen deshalb im Produkt ({@see ScopeAssignment}) — nur dort ist
 * bekannt, dass ein Bereich zu einem zugewiesenen Mandanten gehören muss.
 *
 * ## Ersetzen, nicht hinzufügen
 *
 * Ein Aufruf trägt die vollständige Liste einer Ebene. Kein `modus`, wie ihn
 * die Rollen haben — dort geht es um eine einzelne Rolle, hier um eine Menge.
 * Eine leere Liste heißt „nichts mehr zugewiesen", nicht „nichts ändern".
 *
 * ## Wer es veranlasst hat, ist Pflicht
 *
 * Wörtlich dieselbe Überlegung wie bei den Rollen: Der Weg von AI Brain in die
 * Produkte trägt nur den Maschinen-Token. Ohne `veranlasst_von` stünde im
 * Protokoll auf ewig dieselbe Maschine, und „wer hat dieser Person die
 * Zuständigkeit gegeben" wäre unbeantwortbar. Es ist die Zuschreibung, keine
 * Berechtigung — die Vertrauensgrenze ist der Token.
 */
class AssignScopeTool extends Tool
{
    protected string $description = 'Setzt die Zuständigkeit einer Person auf einer Ebene (z. B. Mandant oder Bereich). Ersetzt die bisherige Zuweisung dieser Ebene vollständig. Wer es veranlasst, muss angegeben werden.';

    public function handle(Request $request): Response
    {
        $email = trim((string) $request->get('email', ''));
        $ebene = trim((string) $request->get('ebene', ''));
        $veranlasstVon = trim((string) $request->get('veranlasst_von', ''));

        $ids = array_values(array_filter(array_map(
            fn ($id): string => trim((string) $id),
            (array) $request->get('ids', []),
        ), fn (string $id): bool => $id !== ''));

        $alle = $request->get('alle');
        $alle = $alle === null ? null : filter_var($alle, FILTER_VALIDATE_BOOL);

        if ($veranlasstVon === '') {
            return Response::error('Ohne `veranlasst_von` wird keine Zuständigkeit geändert. Eine Rechteentscheidung ohne Namen ist im Nachhinein nicht mehr zuzuordnen.');
        }

        // Handelt hier ein MENSCH (Acting-User-Delegation aus einem Channel),
        // muss er es auch duerfen — dasselbe Gate wie bei den Rollen. Ohne das
        // koennte sich jeder, der einen Channel bedienen darf, selbst alle
        // Mandanten geben und damit alles sehen.
        $handelnder = $request->user();

        if ($handelnder !== null && Gate::forUser($handelnder)->denies('ai-brain.assign-roles')) {
            Log::warning('Zuständigkeitsänderung abgewiesen: handelnde Person darf das nicht', [
                'handelnder' => $handelnder->email ?? null,
                'email' => $email,
                'ebene' => $ebene,
            ]);

            return Response::error('🔒 Nicht ausgeführt: Diese Person darf in diesem System keine Zuständigkeiten vergeben. Sag das dem Nutzer, statt es auf einem anderen Weg zu versuchen.');
        }

        $ergebnis = ScopeAssignment::setzen($email, $ebene, $ids, $alle);

        // Auch den abgewiesenen Versuch mitschreiben — wer nur die Erfolge
        // protokolliert, sieht spaeter ausgerechnet das nicht, was er sucht.
        Log::info('Zuständigkeitsänderung über AI Brain', [
            'email' => $email,
            'ebene' => $ebene,
            'ids' => $ids,
            'alle' => $alle,
            'veranlasst_von' => $veranlasstVon,
            'ok' => $ergebnis['ok'],
            'meldung' => $ergebnis['message'],
        ]);

        if (! $ergebnis['ok']) {
            return Response::error($ergebnis['message']);
        }

        return Response::text(json_encode([
            'ok' => true,
            'message' => $ergebnis['message'],
            'email' => $email,
            'person' => [
                'zugewiesen' => $ergebnis['zugewiesen'],
                'alle' => $ergebnis['alle'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('E-Mail-Adresse der Person in DIESEM System. Muss ein bestehendes Konto sein — es wird keines angelegt.')
                ->required(),

            'ebene' => $schema->string()
                ->description('Schlüssel der Ebene, genau wie ihn list-scopes-tool meldet (z. B. `mandant`, `bereich`).')
                ->required(),

            'ids' => $schema->array()
                ->description('Die VOLLSTÄNDIGE Liste der Werte dieser Ebene. Ersetzt die bisherige Zuweisung; eine leere Liste entzieht alles.'),

            'alle' => $schema->boolean()
                ->description('Nur für Ebenen, die `alle_moeglich` melden: statt einer Auswahl gilt „alle" (im CRM `has_all_brands`).'),

            'veranlasst_von' => $schema->string()
                ->description('E-Mail des Menschen in AI Brain, der die Änderung veranlasst. Pflicht — ohne Angabe wird abgewiesen.')
                ->required(),
        ];
    }
}
