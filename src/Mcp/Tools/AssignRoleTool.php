<?php

namespace Peppermint\AiBrainBridge\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\AiBrainBridge\Auth\RoleAssignment;

/**
 * Vergibt oder entzieht eine Rolle in diesem Produkt (AI Brain #5314).
 *
 * Das Gegenstück zu {@see ListRolesTool}: Der meldet, welche Rollen es gibt,
 * dieses setzt sie. Erst beides zusammen macht aus dem Rollen-REGISTER eine
 * Rollen-VERWALTUNG.
 *
 * SCHREIBEND — und zwar an der empfindlichsten Stelle, die ein System hat.
 * Deshalb steht in {@see RoleAssignment} eine Reihe von Riegeln: Rollen werden
 * nie angelegt, Konten nie angelegt, und die letzte Person einer geschützten
 * Rolle wird nicht entfernt.
 *
 * ## Wer es veranlasst hat, ist Pflicht
 *
 * Der Weg von AI Brain in die Produkte trägt heute nur den Maschinen-Token,
 * keinen handelnden Menschen. Für eine Leseabfrage genügt das; für „vergib eine
 * Rolle" nicht — sonst stünde im Protokoll auf ewig dieselbe Maschine, und die
 * Frage „wer hat dieser Person den Zugang gegeben" wäre unbeantwortbar.
 *
 * Deshalb ist `veranlasst_von` ein PFLICHTFELD. Es ist ausdrücklich **keine
 * Berechtigung** — wer den Token hat, darf ohnehin; die Vertrauensgrenze ist
 * der Token. Es ist die Zuschreibung, und ohne sie wird abgewiesen.
 *
 * ## Der Schalter ist die Registrierung
 *
 * Ein Produkt, dessen Rollen nicht aus der Ferne änderbar sein sollen,
 * registriert dieses Werkzeug einfach nicht. Kein Zusatzschalter, keine
 * Umgebungsvariable, nichts, was jemand pflegen muss.
 */
class AssignRoleTool extends Tool
{
    protected string $description = 'Vergibt oder entzieht eine Rolle in diesem System. Legt niemals Rollen oder Konten an und entfernt nie die letzte Person einer geschützten Rolle. Wer es veranlasst, muss angegeben werden.';

    public function handle(Request $request): Response
    {
        $email = trim((string) $request->get('email', ''));
        $rolle = trim((string) $request->get('rolle', ''));
        $modus = trim((string) $request->get('modus', ''));
        $veranlasstVon = trim((string) $request->get('veranlasst_von', ''));

        if ($veranlasstVon === '') {
            return Response::error('Ohne `veranlasst_von` wird keine Rolle geändert. Eine Zugangsentscheidung ohne Namen ist im Nachhinein nicht mehr zuzuordnen.');
        }

        $ergebnis = RoleAssignment::setzen($email, $rolle, $modus);

        // Immer protokollieren — auch den abgewiesenen Versuch. Wer nur die
        // Erfolge mitschreibt, sieht ausgerechnet das nicht, was man spaeter
        // sucht: die Versuche, die nicht durchgingen.
        Log::info('Rollenänderung über AI Brain', [
            'email' => $email,
            'rolle' => $rolle,
            'modus' => $modus,
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
            'rollen' => $ergebnis['rollen'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('E-Mail-Adresse der Person, deren Rolle geändert wird. Muss ein bestehendes Konto sein — es wird keines angelegt.')
                ->required(),

            'rolle' => $schema->string()
                ->description('Technischer Name der Rolle, genau wie ihn list-roles-tool meldet. Unbekannte Namen werden abgewiesen, nicht angelegt.')
                ->required(),

            'modus' => $schema->string()
                ->description("'zuweisen' oder 'entziehen'.")
                ->required(),

            'veranlasst_von' => $schema->string()
                ->description('E-Mail des Menschen in AI Brain, der die Änderung veranlasst. Pflicht — ohne Angabe wird abgewiesen.')
                ->required(),
        ];
    }
}
