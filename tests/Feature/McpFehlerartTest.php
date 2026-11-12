<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Exceptions\AiBrainBridgeException;
use Peppermint\AiBrainBridge\Exceptions\McpAufrufGescheitert;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Der HTTP-Status eines gescheiterten MCP-Aufrufs ist TYPISIERT lesbar.
 *
 * ## Woher das kommt
 *
 * Aufgabe #9882, und der Anlass ist Bug #1417. Bis zum 12.11.2026 stand der
 * Status nur im Meldungstext (`"MCP tools/list failed: HTTP 401"`). Das
 * Kalenderpaket fing jeden `Throwable` und meldete ausnahmslos „die Nabe ist
 * nicht erreichbar" — auch bei einem 401, wo die Nabe sehr wohl geantwortet
 * und nur ABGELEHNT hat. In der Anlege-Maske des Managers stand deshalb
 * „Nicht erreichbar: AI Brain (privat)", wo „nicht freigegeben" richtig war.
 *
 * ## Was diese Reihe prueft, und was ausdruecklich NICHT
 *
 * Geprueft wird die UNTERSCHEIDBARKEIT am Typ, nicht die Deutung. Die
 * Abbildung auf die vier Fehlerarten des Komm-Layers steht beim Aufrufer
 * (heute `peppermint/calendar`), und dort stehen ihre Tests.
 *
 * Der wichtigste Test der Reihe ist der letzte: Die Aenderung ist nur
 * tragbar, wenn sie ADDITIV ist. Gemessen wurde das an den Aufrufern
 * (0 fangen die Oberklasse namentlich, 0 erben, 4 konstruieren — alle vier
 * in der Bruecke selbst); festgehalten wird es hier, weil eine Messung von
 * damals nichts darueber sagt, was morgen gilt.
 */
function brainAntwortetMit(int $status, array $rumpf = []): void
{
    Http::fake(function ($request) use ($status, $rumpf) {
        $url = $request->url();

        if (str_contains($url, '/oauth/token')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        $body = json_decode($request->body(), true);

        // Der Handschlag muss GELINGEN, sonst prueft der Test den Handschlag
        // und nicht den Aufruf danach. `ensureInitialized()` laeuft zuerst
        // und wuerfe sonst zuerst — mit demselben Typ, aber aus dem falschen
        // Grund.
        if (($body['method'] ?? '') === 'initialize') {
            return Http::response(
                ['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => []],
                200,
                ['Mcp-Session-Id' => 'sess-1'],
            );
        }

        return Http::response($rumpf !== [] ? $rumpf + ['id' => $body['id']] : [], $status);
    });
}

beforeEach(function () {
    config()->set('ai-brain-bridge.base_url', 'https://brain.test');
    config()->set('ai-brain-bridge.oauth.client_id', 'cid');
    config()->set('ai-brain-bridge.oauth.client_secret', 'csec');
});

it('traegt den Status einer Absage typisiert — 403', function () {
    brainAntwortetMit(403);

    try {
        AiBrain::call('list-calendar-bundle-tool');
        $this->fail('Es haette werfen muessen.');
    } catch (McpAufrufGescheitert $fehler) {
        expect($fehler->httpStatus)->toBe(403)
            ->and($fehler->istAbsage())->toBeTrue()
            ->and($fehler->methode)->toBe('tools/call');
    }
});

it('traegt den Status einer Absage typisiert — 401', function () {
    // Der Fall aus den Reproduktionsschritten von #1417, woertlich: „leere
    // OAuth-Zugangsdaten reichen, Aufruf endet in HTTP 401". Das ist der
    // Grund, warum 401 und 403 dieselbe Antwort ergeben muessen.
    brainAntwortetMit(401);

    try {
        AiBrain::call('list-calendar-bundle-tool');
        $this->fail('Es haette werfen muessen.');
    } catch (McpAufrufGescheitert $fehler) {
        expect($fehler->httpStatus)->toBe(401)
            ->and($fehler->istAbsage())->toBeTrue();
    }
});

it('unterscheidet eine Stoerung von einer Absage — 500 ist KEINE Absage', function () {
    // Die Gegenrichtung, und sie ist der Zweck: Ohne sie wuerde `istAbsage()`
    // auch bei einem kaputten Server „nicht freigegeben" sagen — dieselbe
    // Luege wie vorher, nur andersherum.
    brainAntwortetMit(500);

    try {
        AiBrain::call('list-calendar-bundle-tool');
        $this->fail('Es haette werfen muessen.');
    } catch (McpAufrufGescheitert $fehler) {
        expect($fehler->httpStatus)->toBe(500)
            ->and($fehler->istAbsage())->toBeFalse();
    }
});

it('unterscheidet ein fehlendes Werkzeug von einer Absage — 404 ist KEINE Absage', function () {
    brainAntwortetMit(404);

    try {
        AiBrain::call('gibt-es-nicht-tool');
        $this->fail('Es haette werfen muessen.');
    } catch (McpAufrufGescheitert $fehler) {
        expect($fehler->httpStatus)->toBe(404)
            ->and($fehler->istAbsage())->toBeFalse();
    }
});

it('fuehrt einen JSON-RPC-Fehler als zugestellt, nicht als Status', function () {
    // Die zwei Ebenen von „ok" aus Wiki #595, hier als Typ: Die ZUSTELLUNG
    // klappte (HTTP 200), die Nutzlast trug den Fehler. `null` heisst
    // deshalb ausdruecklich NICHT „unbekannt", sondern „die Nabe hat
    // geantwortet und das Werkzeug hat abgelehnt".
    brainAntwortetMit(200, ['jsonrpc' => '2.0', 'error' => ['message' => 'Tool not found']]);

    try {
        AiBrain::call('list-calendar-bundle-tool');
        $this->fail('Es haette werfen muessen.');
    } catch (McpAufrufGescheitert $fehler) {
        expect($fehler->httpStatus)->toBeNull()
            ->and($fehler->istAbsage())->toBeFalse()
            ->and($fehler->getMessage())->toContain('Tool not found');
    }
});

it('laesst einen Verbindungsausfall als EIGENEN Typ durch', function () {
    // Die andere Haelfte der typisierten Trennung. Ein Verbindungsausfall
    // darf NICHT zu `McpAufrufGescheitert` werden: „hat nicht geantwortet"
    // und „hat abgelehnt" sind zwei Aussagen, und genau ihre Gleichsetzung
    // war der Fehler in #1417.
    Http::fake(function () {
        throw new ConnectionException('Nabe weg');
    });

    expect(fn () => AiBrain::call('list-calendar-bundle-tool'))
        ->toThrow(ConnectionException::class);

    // Und die Gegenprobe, die den Test erst aussagekraeftig macht: Es ist
    // auch nicht ZUFAELLIG beides. Waere `ConnectionException` eine
    // Unterklasse von `AiBrainBridgeException`, waere die Zusicherung oben
    // gruen und trotzdem wertlos.
    expect(is_a(ConnectionException::class, AiBrainBridgeException::class, true))->toBeFalse();
});

it('ist ADDITIV: jeder bestehende catch auf die Oberklasse faengt sie weiter', function () {
    // Die Auflage zu dieser Aenderung. Gemessen am 12.11.2026 ueber AI Brain,
    // drei Produkte und acht Paketquellen: 0 Stellen fangen
    // `AiBrainBridgeException` namentlich, 0 erben von ihr, 4 konstruieren
    // sie (alle in der Bruecke). Die eigentlichen Verbraucher fangen
    // Oberklassen — `Throwable` 548-mal, `Exception` 77-mal,
    // `RuntimeException` 32-mal.
    //
    // Diese Zusicherung haelt die Zusage fest, auf der die Zahl beruht:
    // Solange die Unterklasse eine ist, aendert sich fuer alle 657 nichts.
    brainAntwortetMit(403);

    $gefangen = null;

    try {
        AiBrain::call('list-calendar-bundle-tool');
    } catch (AiBrainBridgeException $fehler) {
        $gefangen = $fehler;
    }

    expect($gefangen)->toBeInstanceOf(McpAufrufGescheitert::class)
        ->and($gefangen)->toBeInstanceOf(AiBrainBridgeException::class)
        ->and($gefangen)->toBeInstanceOf(RuntimeException::class)
        ->and($gefangen)->toBeInstanceOf(Throwable::class);
});

it('laesst den Meldungstext wortgleich', function () {
    // Der Text steht in Logs und moeglicherweise in Suchmustern. Ihn
    // nebenbei umzuformulieren waere eine zweite Aenderung in einer
    // Aenderung, die additiv sein soll — und sie faende niemand, weil
    // nichts dabei rot wird.
    brainAntwortetMit(503);

    try {
        AiBrain::call('list-calendar-bundle-tool');
        $this->fail('Es haette werfen muessen.');
    } catch (McpAufrufGescheitert $fehler) {
        expect($fehler->getMessage())->toBe('MCP tools/call failed: HTTP 503');
    }
});

it('traegt den Status auch bei der Werkzeugliste, nicht nur beim Aufruf', function () {
    // `listTools()` ist der Weg, an dem Bastians Symptom haengt: Die
    // Freigabe-Pruefung des Kalenderpakets liest die Werkzeugliste, nicht ein
    // Werkzeug. Ein Test nur auf `tools/call` haette die gemeinte Stelle
    // nicht getroffen.
    brainAntwortetMit(403);

    try {
        AiBrain::brain()->listTools();
        $this->fail('Es haette werfen muessen.');
    } catch (McpAufrufGescheitert $fehler) {
        expect($fehler->httpStatus)->toBe(403)
            ->and($fehler->methode)->toBe('tools/list')
            ->and($fehler->istAbsage())->toBeTrue();
    }
});
