<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * `anbieterMitGrund()` — dieselbe Frage wie `providers()`, mit Grund (Bug #1513).
 *
 * Die Lagen sind dieselben, die am 19.11.2026 mit dem echten Client
 * nachgestellt wurden. Dort lieferten vier davon dieselbe leere Liste wie die
 * eine, in der die Liste wirklich leer ist. Hier muss jede ihren Grund tragen
 * — und die echt leere darf KEINEN bekommen.
 *
 * Zweite Haelfte dieser Datei: `providers()` bleibt, wie es war. Fuenf Pakete
 * rufen es, und die Entscheidung fuer eine Schwester-Methode stand genau
 * darauf, dass sie nichts merken.
 */
beforeEach(function () {
    config()->set('ai-brain-bridge.base_url', 'https://brain.test');
    config()->set('ai-brain-bridge.oauth.token_url', 'https://brain.test/oauth/token');
    config()->set('ai-brain-bridge.oauth.client_id', 'produkt');
    config()->set('ai-brain-bridge.oauth.client_secret', 'geheim');
});

function grundAntwortet(mixed $antwort): void
{
    Http::fake([
        'brain.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'brain.test/api/v1/capabilities/*' => $antwort,
    ]);
}

/** Eine Antwort, wie Brain sie bei Erfolg schickt. */
function grundZweiAnbieter(): array
{
    return [
        'ok' => true,
        'capability' => 'tasks.list',
        'providers' => [
            ['slug' => 'peppermint-manager', 'name' => 'Peppermint Manager', 'url' => 'https://pm.test', 'capabilities' => ['tasks.list', 7]],
            ['name' => 'ohne Slug'],
            ['slug' => 'peppermint-crm', 'name' => null],
        ],
    ];
}

// --- Die Lagen ---------------------------------------------------------------

it('A: Brain nicht erreichbar ist unreachable, nicht eine leere Liste', function () {
    grundAntwortet(fn () => throw new ConnectionException('Connection refused'));

    $antwort = AiBrain::anbieterMitGrund('tasks.list');

    expect($antwort['ok'])->toBeFalse()
        ->and($antwort['data'])->toBeNull()
        ->and($antwort['error'])->toBe('unreachable')
        ->and($antwort['message'])->toContain('Connection refused');
});

it('A2: scheitert schon das Token, ist das ebenso unreachable', function () {
    // Der Weg ZU Brain beginnt beim Token. `providers()` faengt dessen
    // Ausnahme ebenso — hier darf sie nicht zur Liste werden.
    Http::fake([
        'brain.test/oauth/token' => Http::response('weg', 503),
        'brain.test/api/v1/capabilities/*' => Http::response(grundZweiAnbieter()),
    ]);

    $antwort = AiBrain::anbieterMitGrund('tasks.list');

    expect($antwort['ok'])->toBeFalse()
        ->and($antwort['error'])->toBe('unreachable');
    Http::assertNotSent(fn ($anfrage) => str_contains($anfrage->url(), '/capabilities/'));
});

it('B: eine Antwort, die kein JSON ist, ist tool_failed mit Status', function () {
    grundAntwortet(Http::response('<html>502 Bad Gateway</html>', 502));

    $antwort = AiBrain::anbieterMitGrund('tasks.list');

    expect($antwort['ok'])->toBeFalse()
        ->and($antwort['error'])->toBe('tool_failed')
        ->and($antwort['message'])->toContain('HTTP 502');
});

it('C: wirklich niemand bedient den Vorgang — ok, leer, ohne Grund', function () {
    // Der Test, der am meisten beweist: Echt leer darf NICHT als Ausfall
    // gelten. Sonst stuende in jeder Maske ein Warnhinweis, wo es schlicht
    // nichts gibt.
    grundAntwortet(Http::response(['ok' => true, 'capability' => 'tasks.list', 'providers' => []]));

    expect(AiBrain::anbieterMitGrund('tasks.list'))->toBe([
        'ok' => true,
        'data' => [],
        'text' => null,
        'error' => null,
        'message' => null,
        'product' => null,
    ]);
});

it('D: fehlt die Freigabe, kommt der Name durch, den Brain schickt', function () {
    // So antwortet `CapabilityProvidersApiController` in AI Brain: 403 mit
    // `not_allowed`. `providers()` hat genau das verworfen.
    grundAntwortet(Http::response([
        'ok' => false,
        'error' => 'not_allowed',
        'message' => 'Fuer „tasks.list" ist dieses System nicht freigeschaltet.',
    ], 403));

    $antwort = AiBrain::anbieterMitGrund('tasks.list');

    expect($antwort['ok'])->toBeFalse()
        ->and($antwort['data'])->toBeNull()
        ->and($antwort['error'])->toBe('not_allowed')
        ->and($antwort['message'])->toContain('nicht freigeschaltet');
});

it('D2: eine Absage ohne Namen bleibt ohne Namen, aber ein Fehlschlag', function () {
    // Passport antwortet auf ein verworfenes Token mit 401 und nur einer
    // Meldung. Die Bruecke deutet den Status NICHT in einen der Namen um —
    // das ist dieselbe Entscheidung wie an `McpAufrufGescheitert`. Ein
    // Fehlschlag bleibt es trotzdem: Er darf nie als Liste ankommen.
    grundAntwortet(Http::response(['message' => 'Unauthenticated.'], 401));

    $antwort = AiBrain::anbieterMitGrund('tasks.list');

    expect($antwort['ok'])->toBeFalse()
        ->and($antwort['error'])->toBeNull()
        ->and($antwort['message'])->toBe('Unauthenticated.');
});

it('D3: ein ok, das kein echtes true ist, ist kein Erfolg', function () {
    // Dieselbe strenge Lesart wie `call()` (Bug #1425): `(bool) 'false'`
    // waere true und gaebe eine leere Liste als vollstaendige Auskunft aus.
    grundAntwortet(Http::response(['ok' => 'false', 'providers' => []]));

    $antwort = AiBrain::anbieterMitGrund('tasks.list');

    expect($antwort['ok'])->toBeFalse()
        ->and($antwort['message'])->toContain('HTTP 200');
});

it('E: bei Erfolg stehen in data dieselben Zeilen wie aus providers()', function () {
    grundAntwortet(Http::response(grundZweiAnbieter()));

    $mitGrund = AiBrain::anbieterMitGrund('tasks.list');

    expect($mitGrund['ok'])->toBeTrue()
        ->and($mitGrund['error'])->toBeNull()
        ->and($mitGrund['data'])->toBe(AiBrain::providers('tasks.list'))
        ->and($mitGrund['data'])->toBe([
            [
                'slug' => 'peppermint-manager',
                'name' => 'Peppermint Manager',
                'key' => 'manager',
                'label' => 'Manager',
                'url' => 'https://pm.test',
                'capabilities' => ['tasks.list'],
            ],
            [
                'slug' => 'peppermint-crm',
                'name' => null,
                'key' => 'crm',
                'label' => 'crm',
                'url' => null,
                'capabilities' => [],
            ],
        ]);
});

it('fragt dieselbe Adresse wie providers(), mit Token', function () {
    grundAntwortet(Http::response(['ok' => true, 'providers' => []]));

    AiBrain::anbieterMitGrund('tasks.subject.list');

    Http::assertSent(fn ($anfrage) => $anfrage->method() === 'GET'
        && str_ends_with($anfrage->url(), '/api/v1/capabilities/tasks.subject.list/providers')
        && $anfrage->hasHeader('Authorization', 'Bearer tok'));
});

// --- providers() bleibt, wie es war --------------------------------------------

it('laesst providers() in jeder Ausfall-Lage bei der leeren Liste', function (mixed $antwort) {
    grundAntwortet($antwort);

    expect(AiBrain::providers('tasks.list'))->toBe([]);
})->with([
    'nicht erreichbar' => fn () => fn () => throw new ConnectionException('weg'),
    'kein JSON' => fn () => Http::response('<html>502</html>', 502),
    'not_allowed' => fn () => Http::response(['ok' => false, 'error' => 'not_allowed'], 403),
    'Passport 401' => fn () => Http::response(['message' => 'Unauthenticated.'], 401),
    'echt leer' => fn () => Http::response(['ok' => true, 'providers' => []]),
    // Eine Absage, die trotzdem Zeilen mitschickt, bleibt eine Absage —
    // auch nachdem die Zeilenform in `anbieterZeilen()` gewandert ist.
    'ok false mit Zeilen' => fn () => Http::response(['ok' => false, 'error' => 'not_allowed', 'providers' => [['slug' => 'peppermint-crm']]], 403),
]);
