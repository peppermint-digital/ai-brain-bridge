<?php

namespace Peppermint\AiBrainBridge\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\AiBrainBridge\Auth\ScopeCatalog;

/**
 * Meldet die Zuständigkeits-Ebenen dieses Produkts an AI Brain (#9007).
 *
 * Das Gegenstück zu {@see ListRolesTool} für die zweite Hälfte der Rechte: Eine
 * Rolle sagt, WAS jemand darf, eine Zuständigkeit WORAN. Im CRM entscheiden
 * Mandant und Bereich über die Sichtbarkeit jedes einzelnen Vorgangs — sie sind
 * damit genauso Rechteverwaltung wie die Rolle und gehören an dieselbe Stelle.
 *
 * Ein Produkt registriert das Werkzeug an seinem MCP-Server und hinterlegt die
 * beiden Resolver in {@see ScopeCatalog}. Ohne Resolver meldet es eine leere
 * Ebenenliste, und in AI Brain erscheint nichts — kein Schalter, den jemand
 * pflegen muss.
 *
 * LESEND. Mit `email` kommt zusätzlich die Zuweisung GENAU DIESER Person mit.
 * Das ist kein zweites Werkzeug geworden, weil AI Brain beides in derselben
 * Maske braucht: Ohne Katalog gäbe es keine Auswahl, ohne Zuweisung keinen
 * Haken an der richtigen Stelle.
 */
class ListScopesTool extends Tool
{
    protected string $description = 'Nennt die Zuständigkeits-Ebenen dieses Systems (z. B. Mandant und darunter Bereich) mit ihren möglichen Werten. Mit `email` kommt die Zuweisung dieser Person mit. Rein lesend — für die Benutzerseite in AI Brain.';

    public function handle(Request $request): Response
    {
        $ebenen = ScopeCatalog::ebenen();
        $email = trim((string) $request->get('email', ''));

        $antwort = [
            'ebenen' => $ebenen,
            'count' => count($ebenen),
        ];

        if ($email !== '') {
            $antwort['email'] = $email;
            $antwort['person'] = ScopeCatalog::person($email);
        }

        return Response::text(json_encode($antwort, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('Optional: E-Mail-Adresse eines Kontos in DIESEM System. Dann kommt zusätzlich die Zuweisung dieser Person mit.'),
        ];
    }
}
