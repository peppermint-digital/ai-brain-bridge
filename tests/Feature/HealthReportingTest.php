<?php

use Peppermint\AiBrainBridge\Health\HealthCollector;
use Peppermint\AiBrainBridge\Health\HealthReporting;

/**
 * K7 (AI Brain #5239): Der Gesundheits-Push kam aus dem eigenständigen Paket
 * `ai-brain/laravel-connector` und wohnt jetzt hier — ein Paket, ein
 * Konfigurationsblock, eine Gegenstelle.
 */
it('meldet, solange es eingeschaltet ist', function () {
    config()->set('ai-brain-bridge.health.enabled', true);

    expect(HealthReporting::aktiv())->toBeTrue();
});

it('meldet nicht, wenn es abgeschaltet ist', function () {
    config()->set('ai-brain-bridge.health.enabled', false);

    expect(HealthReporting::aktiv())->toBeFalse();
});

it('tritt zurück, solange das abgelöste Paket noch installiert ist', function () {
    config()->set('ai-brain-bridge.health.enabled', true);

    // Das alte Paket ist in den Produkten noch installiert und meldet weiter.
    // Ohne diesen Rücktritt liefe die Meldung während der Umstellung doppelt —
    // und zwei Meldungen derselben App im Fünf-Minuten-Takt sehen in AI Brain
    // aus wie ein flatternder Dienst, nicht wie ein Umbau.
    expect(class_exists(HealthReporting::ALTES_PAKET))->toBeFalse('Vorbedingung: das alte Paket ist hier nicht installiert');

    eval('namespace AiBrain\Connector; class ConnectorServiceProvider {}');

    expect(HealthReporting::aktiv())->toBeFalse();
});

it('übernimmt die ENV-Namen des abgelösten Pakets unverändert', function () {
    // Ein Produkt, das das alte Paket entfernt, soll nichts umtragen müssen.
    expect(config('ai-brain-bridge.health.enabled'))->not->toBeNull()
        ->and(config('ai-brain-bridge.health.schedule'))->toBe('everyFiveMinutes')
        ->and(config('ai-brain-bridge.health.slow_queries.threshold_ms'))->toBe(1000)
        ->and(config('ai-brain-bridge.health.exceptions.max_items'))->toBe(10);
});

it('sammelt einen Schnappschuss mit Namen und Datenbank-Zustand', function () {
    $schnappschuss = app(HealthCollector::class)->collect(flush: false);

    expect($schnappschuss)->toHaveKey('app_name')
        ->and($schnappschuss)->toHaveKey('db_ok')
        ->and($schnappschuss['app_name'])->not->toBeEmpty();
});
