<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Peppermint\AiBrainBridge\Http\Middleware\BeschraenkeKanalWerkzeuge;
use Peppermint\AiBrainBridge\Http\Middleware\ResolveAiBrainActingUser;
use Peppermint\AiBrainBridge\Mcp\KanalWerkzeugGrant;

/**
 * Der Werkzeug-Riegel je Channel (AI-Brain-Bug #1226, Aufgabe #9278).
 *
 * ## Warum es diesen Riegel gibt
 *
 * Die per-Channel-Werkzeugauswahl lag bis zum 01.11.2026 ausschliesslich im
 * Aufrufer und wirkte dort als `allowedTools` des Claude-SDK — das gibt nur
 * ohne Rueckfrage frei und verbietet nichts (AI-Brain-Bug #588). Am 01.11.2026
 * hat ein Agent in der Produktion damit geschrieben.
 *
 * Und der Riegel im Aufrufer (`disallowedTools`) schliesst nur den Weg ueber
 * den MCP-Klienten: Der Acting-Nachweis steht in der Prozess-Umgebung des
 * Containers, und der Agent hat eine Shell (AI-Brain-Bug #1229). Deshalb hier.
 *
 * ## Die vier Zusicherungen, auf die es ankommt
 *
 * 1. **Ohne Grant aendert sich NICHTS.** Jeder Aufrufer, der ihn nicht kennt,
 *    laeuft bitgenau wie bisher — in `report` wie in `off`.
 * 2. **Melden heisst melden.** Der Startzustand weist nichts ab.
 * 3. **Scharf heisst scharf**, und zwar fuer das einzelne `tools/call` und
 *    nicht fuer die Verbindung.
 * 4. **Manipulation, Ablauf und fremder Kanal sind kein Grant.**
 */
function grantHeaders(string $grant, string $email = 'chris@example.test', ?string $kanal = null): array
{
    return array_filter([
        BeschraenkeKanalWerkzeuge::GRANT_HEADER => $grant,
        ResolveAiBrainActingUser::ACTING_USER_HEADER => $email,
        ResolveAiBrainActingUser::CHANNEL_HEADER => $kanal,
    ]);
}

function aufruf(string $werkzeug): array
{
    return ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $werkzeug]];
}

beforeEach(function () {
    config()->set('ai-brain-bridge.events.secret', 'produkt-secret');
    config()->set('ai-brain-bridge.kanal_werkzeuge.modus', 'enforce');

    Route::middleware([ResolveAiBrainActingUser::class, BeschraenkeKanalWerkzeuge::class])
        ->post('/_test/mcp', fn () => response('durchgelassen'));

    $this->grant = KanalWerkzeugGrant::ausstellen(
        'beleg-extraktion',
        ['list-suppliers-tool', 'create-incoming-invoice-tool'],
        'produkt-secret',
        3600,
        'lauf-1',
    );
});

it('laesst ein Werkzeug aus der Liste durch', function () {
    $this->postJson('/_test/mcp', aufruf('create-incoming-invoice-tool'), grantHeaders($this->grant))
        ->assertOk()
        ->assertSee('durchgelassen');
});

it('weist ein Werkzeug ausserhalb der Liste ab', function () {
    // Der Fall, um den es geht: genau so hat der Agent am 01.11.2026
    // geschrieben.
    $this->postJson('/_test/mcp', aufruf('delete-incoming-invoice-tool'), grantHeaders($this->grant))
        ->assertOk()
        ->assertDontSee('durchgelassen')
        ->assertSee('Nicht ausgefuehrt');
});

it('weist einen manipulierten Grant ab', function () {
    // Ein Werkzeug in die Nutzlast schreiben, ohne das Secret zu kennen.
    [$nutzlast] = explode('.sha256=', $this->grant, 2);
    $daten = json_decode(base64_decode(strtr($nutzlast, '-_', '+/')), true);
    $daten['werkzeuge'][] = 'delete-incoming-invoice-tool';
    $gefaelscht = rtrim(strtr(base64_encode(json_encode($daten)), '+/', '-_'), '=')
        .'.sha256='.explode('.sha256=', $this->grant, 2)[1];

    $this->postJson('/_test/mcp', aufruf('delete-incoming-invoice-tool'), grantHeaders($gefaelscht))
        ->assertOk()
        ->assertDontSee('durchgelassen');
});

it('weist einen abgelaufenen Grant ab', function () {
    // Kurzlebig ist die halbe Zusicherung: Ein Grant, der nicht abläuft, ist
    // ein Dauerausweis.
    $abgelaufen = KanalWerkzeugGrant::ausstellen(
        'beleg-extraktion',
        ['create-incoming-invoice-tool'],
        'produkt-secret',
        -10,
    );

    $this->postJson('/_test/mcp', aufruf('create-incoming-invoice-tool'), grantHeaders($abgelaufen))
        ->assertOk()
        ->assertDontSee('durchgelassen');
});

it('weist einen Grant fuer einen fremden Kanal ab', function () {
    // Die unsignierte Kopfzeile nennt einen anderen Kanal als der signierte
    // Grant. Das ist ein Widerspruch an der Strecke und wird gemeldet, nicht
    // ausgelegt.
    $this->postJson(
        '/_test/mcp',
        aufruf('create-incoming-invoice-tool'),
        grantHeaders($this->grant, kanal: 'email_department'),
    )
        ->assertOk()
        ->assertDontSee('durchgelassen');
});

it('weist einen Grant ab, der mit einem fremden Secret signiert ist', function () {
    // Der Container sieht das Produkt-Secret nie — das ist der Grund, warum
    // ein `curl` aus dem Container keinen Grant bauen kann (Bug #1229).
    $fremd = KanalWerkzeugGrant::ausstellen(
        'beleg-extraktion',
        ['delete-incoming-invoice-tool'],
        'anderes-secret',
    );

    $this->postJson('/_test/mcp', aufruf('delete-incoming-invoice-tool'), grantHeaders($fremd))
        ->assertOk()
        ->assertDontSee('durchgelassen');
});

it('weist im scharfen Modus einen KANAL-Aufruf ohne Grant ab', function () {
    // Ein Aufruf, der sich auf einen Kanal beruft, aber keinen Grant trägt:
    // Entweder schickt die Strecke ihn noch nicht mit, oder jemand hat ihn
    // weggelassen. Beides gehört abgewiesen, sobald scharf gestellt ist.
    $this->postJson('/_test/mcp', aufruf('create-incoming-invoice-tool'), [
        ResolveAiBrainActingUser::ACTING_USER_HEADER => 'chris@example.test',
        ResolveAiBrainActingUser::CHANNEL_HEADER => 'beleg-extraktion',
    ])
        ->assertOk()
        ->assertDontSee('durchgelassen');
});

it('lässt einen Acting-User OHNE Kanal und ohne Grant durch — auch scharf', function () {
    /*
     * Der Fall, den der Melde-Modus am 01.11.2026 in der Produktion aufgedeckt
     * hat: Der Faehigkeiten-Lauf und der Komm-Layer rufen als MENSCH, mit
     * Acting-User und ohne Grant. Zehn Eintraege in wenigen Minuten, alle
     * legitim, alle auf lesende Werkzeuge.
     *
     * Die erste Fassung dieses Riegels hätte sie scharf abgewiesen — also den
     * Komm-Layer lahmgelegt. Dieser Test hält die Korrektur fest: Wer keinen
     * Kanal behauptet, ist kein Kanal-Aufruf.
     */
    $this->postJson('/_test/mcp', aufruf('delete-incoming-invoice-tool'), [
        ResolveAiBrainActingUser::ACTING_USER_HEADER => 'chris@example.test',
    ])
        ->assertOk()
        ->assertSee('durchgelassen');
});

/*
|--------------------------------------------------------------------------
| Zusicherung 1: Ohne Grant aendert sich NICHTS
|--------------------------------------------------------------------------
*/

it('laesst einen Aufruf ohne Acting-User und ohne Grant unberuehrt', function () {
    // Jede Oberflaeche, jeder Cron, jedes Produkt im Dienst-Modus. Diese
    // Zusicherung ist der Grund, warum der Riegel gefahrlos ausgerollt werden
    // kann — auch scharf.
    $this->postJson('/_test/mcp', aufruf('delete-incoming-invoice-tool'))
        ->assertOk()
        ->assertSee('durchgelassen');
});

it('tut im Modus off gar nichts', function () {
    config()->set('ai-brain-bridge.kanal_werkzeuge.modus', 'off');

    $this->postJson('/_test/mcp', aufruf('delete-incoming-invoice-tool'), grantHeaders($this->grant))
        ->assertOk()
        ->assertSee('durchgelassen');
});

/*
|--------------------------------------------------------------------------
| Zusicherung 2: Melden heisst melden
|--------------------------------------------------------------------------
*/

it('laesst im Melde-Modus durch und protokolliert „waere abgewiesen"', function () {
    config()->set('ai-brain-bridge.kanal_werkzeuge.modus', 'report');

    Log::spy();

    $this->postJson('/_test/mcp', aufruf('delete-incoming-invoice-tool'), grantHeaders($this->grant))
        ->assertOk()
        ->assertSee('durchgelassen');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $nachricht, array $kontext = []) => str_contains($nachricht, 'waere abgewiesen'))
        ->once();
});

/*
|--------------------------------------------------------------------------
| Zusicherung 3: nur `tools/call`
|--------------------------------------------------------------------------
*/

it('beurteilt nur tools/call und nicht den Verbindungsaufbau', function () {
    // Ein Riegel am `initialize` wuerde den Channel gar nicht hochkommen
    // lassen; eine Liste zu lesen ist kein Vorgang.
    foreach (['initialize', 'tools/list', 'ping'] as $methode) {
        $this->postJson('/_test/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $methode], [
            ResolveAiBrainActingUser::ACTING_USER_HEADER => 'chris@example.test',
        ])
            ->assertOk()
            ->assertSee('durchgelassen');
    }
});

it('legt den gepruefften Grant fuer die Anwendung bereit', function () {
    Route::middleware([ResolveAiBrainActingUser::class, BeschraenkeKanalWerkzeuge::class])
        ->post('/_test/grant', fn (Illuminate\Http\Request $r) => response(
            $r->attributes->get(BeschraenkeKanalWerkzeuge::GRANT_ATTRIBUTE)?->kanal ?? 'keiner'
        ));

    $this->postJson('/_test/grant', aufruf('create-incoming-invoice-tool'), grantHeaders($this->grant))
        ->assertOk()
        ->assertSee('beleg-extraktion');
});

it('behandelt einen vorgelegten, aber ungueltigen Grant auch OHNE Kanal-Kopfzeile als Befund', function () {
    /*
     * Die Gegenprobe zur Verengung der Regel: „Kein Kanal behauptet" lässt
     * einen Aufruf durch — aber nur, wenn auch kein Grant vorgelegt wurde.
     * Sonst entkäme eine Fälschung, indem sie die Kopfzeile weglässt.
     *
     * Der Unterschied steht als eigene Auskunft im Prüfergebnis (`vorgelegt`)
     * und nicht als Textvergleich auf den Grund: Beim nächsten neuen Grund
     * hätte ein Textvergleich eine stille Lücke.
     */
    $this->postJson('/_test/mcp', aufruf('create-incoming-invoice-tool'), [
        BeschraenkeKanalWerkzeuge::GRANT_HEADER => 'nutzlast.sha256=gefaelscht',
        ResolveAiBrainActingUser::ACTING_USER_HEADER => 'chris@example.test',
    ])
        ->assertOk()
        ->assertDontSee('durchgelassen');
});
