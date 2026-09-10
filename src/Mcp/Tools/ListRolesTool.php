<?php

namespace Peppermint\AiBrainBridge\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\AiBrainBridge\Auth\RoleCatalog;

/**
 * Meldet die Rollen dieses Produkts an AI Brain (#5245).
 *
 * Ein Produkt registriert dieses Werkzeug an seinem MCP-Server:
 *
 *     protected array $tools = [
 *         \Peppermint\AiBrainBridge\Mcp\Tools\ListRolesTool::class,
 *         …
 *     ];
 *
 * Mehr ist nicht zu tun, wenn die Rollen von `spatie/laravel-permission`
 * kommen. Wessen Rollen woanders liegen, hinterlegt einmalig einen Resolver
 * ({@see RoleCatalog::resolveUsing()}).
 *
 * ## Warum im Paket und nicht dreimal im Produkt
 *
 * Weil drei Produkte dieselbe Frage beantworten müssen und drei Antworten
 * dreimal anders altern. Das Paket gibt die FORM vor — Name, Klartext,
 * Rechte, Anzahl der Träger —, das Produkt füllt sie mit dem, was bei ihm gilt.
 *
 * LESEND. Dieses Werkzeug ändert nichts und vergibt nichts; es sagt nur, was es
 * gibt. Wer welche Rolle bekommt, entscheidet weiterhin das Produkt.
 */
class ListRolesTool extends Tool
{
    protected string $description = 'Nennt die Rollen dieses Systems: technischer Name, Klartext-Bezeichnung, zugehörige Rechte und wie viele Personen sie tragen. Rein lesend — für das Verzeichnis in AI Brain.';

    public function handle(Request $request): Response
    {
        $rollen = RoleCatalog::alle();

        return Response::text(json_encode([
            'count' => count($rollen),
            'data' => $rollen,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
