<?php

use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Mcp\McpClient;

/**
 * Der Riegel gegen den offenen MCP-Weg (Aufgabe #9882).
 *
 * ## Was entfernt wurde und warum
 *
 * `AiBrainManager::mcp(string $url)` gab einen MCP-Client fuer eine BELIEBIGE
 * Adresse heraus. Damit konnte ein Produkt jedes andere direkt ansprechen —
 * ohne Freigabe in `comm_layer.gateway_grants` und ohne Fail-closed. Der
 * Docblock nannte es selbst „z.B. ein Produkt-MCP-Server".
 *
 * Gemessen am 12.11.2026 ueber AI Brain, drei Produkte
 * (app/routes/config/database/tests, dazu die 24 nanoclaw-Pakete) und die
 * Quellen von acht Paketen: **null Aufrufer im Code.** Das einzige Vorkommen
 * stand in der README — als Beispiel, direkt ueber dem Gateway-Abschnitt, der
 * den richtigen Weg lehrt. Wer sie las, lernte die Abkuerzung zuerst.
 *
 * ## Der Riegel fragt nach dem TYP, nicht nach dem NAMEN
 *
 * Ein `method_exists(..., 'mcp')` haette eine SCHREIBWEISE geprueft: Die
 * naechste Fassung heisst `mcpFuer()`, `client()` oder `fuerUrl()`, und der
 * Riegel bleibt gruen. Deshalb geht er ueber alle oeffentlichen Methoden und
 * fragt, **was herauskommt** — ein `McpClient` darf nur aus `brain()` kommen,
 * und `brain()` baut seine Adresse aus der Konfiguration statt sie
 * anzunehmen.
 *
 * ## Und die Richtung 2, ohne die Richtung 1 falsch gelesen wird
 *
 * Ein Riegel, der nur sagt „dieser Weg darf nicht existieren", liest sich wie
 * „hier wurde etwas geloescht" — und der Naechste stellt es als verlorene
 * Funktion wieder her. Deshalb sichert dieselbe Reihe zu, dass der RICHTIGE
 * Weg da ist (`gateway()`, `providers()`). Die Methode dazu steht im Wiki
 * („Die zentrale Antwort existiert — gerufen wird sie nicht"): Richtung 1
 * allein ist zweideutig.
 *
 * ## ⛔ WAS DIESER RIEGEL NICHT SIEHT
 *
 * Ausdruecklich, weil ein Riegel, dessen Grenze niemand kennt, mehr Schaden
 * anrichtet als keiner:
 *
 *  1. **`new McpClient($beliebigeUrl, …)` direkt.** Die Klasse ist nicht
 *     `final`, ihr Konstruktor ist oeffentlich. Sie traegt seit dem
 *     12.11.2026 ein `@internal` mit der Begruendung — aber `@internal` ist
 *     eine Bitte, die kein Werkzeug erzwingt. Den Konstruktor zu schliessen
 *     ist eine brechende Aenderung an einem veroeffentlichten Paket und liegt
 *     als eigene Entscheidung vor.
 *  2. **Eine Methode OHNE deklarierten Rueckgabetyp**, die trotzdem einen
 *     `McpClient` liefert. Reflexion sieht dann nichts. Dagegen hilft nur,
 *     dass dieses Paket durchgaengig Rueckgabetypen deklariert — eine
 *     Gewohnheit, nicht eine Zusage.
 *  3. **Alles ausserhalb der Repos, die gemessen wurden.** Ein fremder
 *     Abnehmer dieses Pakets ist nicht erfasst.
 *
 * Er haelt also eine KLASSE von Umwegen, nicht alle. Was er dafuer besser
 * kann als eine Quelltextsuche: Er fragt das echte Symbol. Der Fall aus
 * Bug #1441 — ein Suchmuster passt nicht und der Riegel laesst durch — kann
 * ihm nicht passieren.
 */

/**
 * Alle oeffentlichen Methoden, die einen {@see McpClient} herausgeben.
 *
 * @return list<string>
 */
function methodenMitMcpClient(): array
{
    $gefunden = [];

    foreach ((new ReflectionClass(AiBrainManager::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
        $typ = $methode->getReturnType();

        // Ein Union-Typ (`McpClient|null`) wird mitgelesen: Sonst genuegte ein
        // `?McpClient`, um am Riegel vorbeizukommen.
        $teile = $typ instanceof ReflectionUnionType ? $typ->getTypes() : ($typ !== null ? [$typ] : []);

        foreach ($teile as $teil) {
            if ($teil instanceof ReflectionNamedType && ! $teil->isBuiltin()
                && is_a($teil->getName(), McpClient::class, true)) {
                $gefunden[] = $methode->getName();

                break;
            }
        }
    }

    sort($gefunden);

    return $gefunden;
}

it('gibt einen McpClient NUR aus brain() heraus — kein Weg fuer eine beliebige Adresse', function () {
    expect(methodenMitMcpClient())->toBe(['brain']);
});

it('und brain() nimmt seine Adresse aus der Konfiguration, statt sie anzunehmen', function () {
    // Der Unterschied, auf den es ankommt: `brain()` kann nicht auf ein
    // fremdes Produkt gerichtet werden, weil es keinen Parameter dafuer hat.
    // Ohne diese Zusicherung waere der Riegel oben erfuellbar, indem man
    // `brain(string $url)` daraus macht.
    $brain = new ReflectionMethod(AiBrainManager::class, 'brain');

    expect($brain->getNumberOfParameters())->toBe(0);
});

it('RICHTUNG 2: der richtige Weg ist da — sonst liest sich der Riegel als Verlust', function () {
    // Ohne das hier wuerde der Riegel oben wie eine Loeschung aussehen, und
    // der Naechste stellt `mcp()` als „verlorene Funktion" wieder her. Es ist
    // keine Loeschung, sondern ein Umzug: Der Weg zu einem anderen Produkt
    // heisst `gateway()`, und `providers()` sagt, wer einen Vorgang bedient.
    expect(method_exists(AiBrainManager::class, 'gateway'))->toBeTrue()
        ->and(method_exists(AiBrainManager::class, 'providers'))->toBeTrue();

    // Und `gateway()` nennt einen VORGANG zuerst, nicht ein Zielsystem: Der
    // erste Parameter ist die Faehigkeit, das Produkt ist optional und steht
    // hinten.
    $gateway = new ReflectionMethod(AiBrainManager::class, 'gateway');
    $parameter = $gateway->getParameters();

    expect($parameter[0]->getName())->toBe('capability')
        ->and($parameter[2]->getName())->toBe('product')
        ->and($parameter[2]->isOptional())->toBeTrue();
});

it('die Klasse selbst sagt, dass sie nicht selbst gebaut werden soll', function () {
    // Die Anheftstelle: Wer die Abkuerzung wieder bauen will, kommt an dieser
    // Klasse vorbei, nicht an einem Wiki-Artikel. Geprueft wird, dass der
    // Hinweis DA ist — nicht, dass er wirkt; `@internal` erzwingt nichts.
    //
    // Das ist zugegeben eine Pruefung auf eine Zeichenfolge, also auf eine
    // SCHREIBWEISE. Sie ist hier vertretbar, weil der Gegenstand selbst eine
    // Zeichenfolge IST: Ein Docblock-Hinweis kann nicht anders geprueft
    // werden als an seinem Text. Fuer das VERHALTEN sichern die drei Tests
    // darueber zu.
    $kopf = (new ReflectionClass(McpClient::class))->getDocComment();

    expect($kopf)->toBeString()
        ->and($kopf)->toContain('@internal')
        ->and($kopf)->toContain('gateway()');
});
