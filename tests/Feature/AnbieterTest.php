<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Welche Systeme bedienen einen Vorgang? (AI Brain #7490)
 *
 * Gegenstueck zu `gateway()`, das das Zielsystem bewusst verschweigt. Fuer
 * einen Kalender ist es Inhalt: Jedes angebundene System wird eine eigene
 * Quelle mit Farbe, Filterknopf und „hier anlegen".
 */
beforeEach(function () {
    config()->set('ai-brain-bridge.base_url', 'https://brain.test');
    config()->set('ai-brain-bridge.oauth.token_url', 'https://brain.test/oauth/token');
    config()->set('ai-brain-bridge.oauth.client_id', 'produkt');
    config()->set('ai-brain-bridge.oauth.client_secret', 'geheim');
});

function brainAntwortet(mixed $antwort): void
{
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/capabilities/*' => $antwort,
    ]);
}

it('nennt die Systeme samt Adresse', function () {
    brainAntwortet(Http::response([
        'ok' => true,
        'capability' => 'calendar.events.list',
        'providers' => [
            [
                'slug' => 'peppermint-manager',
                'name' => 'Peppermint Manager',
                'url' => 'https://pm.test',
                'capabilities' => ['calendar.events.list', 'calendar.events.create'],
            ],
        ],
    ]));

    expect(AiBrain::providers('calendar.events.list'))->toBe([
        [
            'slug' => 'peppermint-manager',
            'name' => 'Peppermint Manager',
            'url' => 'https://pm.test',
            'capabilities' => ['calendar.events.list', 'calendar.events.create'],
        ],
    ]);

    Http::assertSent(fn ($anfrage) => str_contains($anfrage->url(), '/api/v1/capabilities/calendar.events.list/providers'));
});

it('ergaenzt fehlende Angaben mit null, statt die Zeile zu verwerfen', function () {
    // Name und Adresse sind Beiwerk — ohne sie bleibt das System ansprechbar,
    // es hat nur kein Sprungziel. Der Slug ist es nicht: Mit ihm wird der
    // naechste Aufruf adressiert.
    brainAntwortet(Http::response([
        'ok' => true,
        'providers' => [
            ['slug' => 'peppermint-verwaltung'],
            ['name' => 'Ohne Slug'],
        ],
    ]));

    expect(AiBrain::providers('calendar.events.list'))->toBe([
        ['slug' => 'peppermint-verwaltung', 'name' => null, 'url' => null, 'capabilities' => []],
    ]);
});

it('ist bei fehlender Freigabe leer und wirft nicht', function () {
    // Wer die Anbieter nicht erfragen darf, hat eben keine fremden Quellen —
    // und der EIGENE Kalender soll davon nicht leer werden. Eine Ausnahme
    // hier naehme die ganze Seite mit.
    brainAntwortet(Http::response(['ok' => false, 'error' => 'not_allowed'], 403));

    expect(AiBrain::providers('calendar.events.list'))->toBe([]);
});

it('ist auch leer, wenn Brain gar nicht antwortet', function () {
    brainAntwortet(fn () => throw new ConnectionException('Zeitueberschreitung'));

    // Die Erwartung IST der Nachweis: Waere die Ausnahme durchgeschlagen,
    // kaeme es hier gar nicht an. `throwsNoExceptions()` daneben macht den
    // Test „risky", weil Pest dann keine Zusicherung mehr erwartet.
    expect(AiBrain::providers('calendar.events.list'))->toBe([]);
});

it('ist leer, wenn die Antwort kein JSON ist', function () {
    // Ein Reverse-Proxy, der eine HTML-Fehlerseite liefert, ist der haeufigste
    // Fall davon — und er darf nicht wie „ein Anbieter" aussehen.
    brainAntwortet(Http::response('<html>502</html>', 502));

    expect(AiBrain::providers('calendar.events.list'))->toBe([]);
});

it('wirft Faehigkeiten weg, die keine Zeichenketten sind', function () {
    // Eine Liste, in der eine Zahl steht, wuerde spaeter gegen Vorgangsnamen
    // verglichen — und `in_array` mit lockerem Vergleich haelt 0 fuer jeden
    // Namen. Lieber hier aussortieren als dort raten.
    brainAntwortet(Http::response([
        'ok' => true,
        'providers' => [[
            'slug' => 'peppermint-manager',
            'capabilities' => ['calendar.events.list', 0, null, ['verschachtelt']],
        ]],
    ]));

    expect(AiBrain::providers('calendar.events.list')[0]['capabilities'])->toBe(['calendar.events.list']);
});
