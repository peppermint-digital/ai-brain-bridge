<?php

use Peppermint\AiBrainBridge\Verbund\Huelle;
use Peppermint\AiBrainBridge\Verbund\Systemnamen;

/**
 * Der geteilte Transport (#8206).
 *
 * Diese drei Stuecke lagen bis zum 19.10.2026 in zwei Paketen doppelt. Sie
 * gehoeren hierher, weil sie weder Benachrichtigung noch Inbox sind,
 * sondern „ein anderes System ueber den Komm-Layer fragen".
 */
describe('Die MCP-Huelle schaelen', function (): void {
    it('findet die Nutzlast in jeder Huelle, die auf der Leitung vorkommt', function (string $huelle): void {
        $nutzlast = ['items' => [['title' => 'Etwas']], 'unread_count' => 3];

        $antwort = $huelle === '' ? $nutzlast : [$huelle => $nutzlast];

        expect(Huelle::zeilen(Huelle::schaelen($antwort)))->toHaveCount(1);
    })->with(['', 'data', 'structuredContent', 'content', 'result', 'antwort']);

    it('gibt eine leere Huelle zurueck, statt zu raten', function (): void {
        // Der gefaehrliche Ausgang: Wer die Huelle nicht findet, bekommt
        // eine LEERE Liste und keinen Fehler — das sieht aus wie „das
        // andere System hat nichts" und heisst „wir haben nicht
        // hingesehen". Deshalb darf es diese Logik nur EINMAL geben.
        expect(Huelle::schaelen(['irgendwas' => ['nicht_items' => []]]))->toBe([]);
    });

    it('wirft weg, was keine Zeile ist', function (): void {
        $huelle = Huelle::schaelen(['items' => [['title' => 'Gut'], 'kaputt', 42, null]]);

        expect(Huelle::zeilen($huelle))->toHaveCount(1);
    });

    it('liest eine Woerterliste und laesst Leeres draussen', function (): void {
        $huelle = Huelle::schaelen(['items' => [], 'kinds' => ['aufgabe', '', null, 'meldung', 7]]);

        expect(Huelle::woerter($huelle, 'kinds'))->toBe(['aufgabe', 'meldung']);
    });
});

describe('Die Systemnamen', function (): void {
    it('nimmt den Konfigurationsschluessel, den das Paket mitgibt', function (): void {
        // Zwei Pakete, zwei Listen, eine Mechanik: Ein Produkt, das nur
        // eines von beiden benutzt, soll nicht die Datei des anderen
        // pflegen muessen.
        config(['inbox.labels.local' => 'Hier drin', 'notifications.labels.local' => 'Woanders']);

        expect(Systemnamen::eigenes('inbox'))->toBe('Hier drin')
            ->and(Systemnamen::eigenes('notifications'))->toBe('Woanders');
    });

    it('faellt auf den Anwendungsnamen zurueck, wenn nichts eingetragen ist', function (): void {
        config(['inbox.labels.local' => null, 'app.name' => 'Peppermint Manager']);

        expect(Systemnamen::eigenes('inbox'))->toBe('Peppermint Manager');
    });

    it('laesst kaputte Eintraege draussen, statt die Liste zu verwerfen', function (): void {
        // Gutmuetig: Ein fehlender oder falscher Eintrag kostet SEIN Wort,
        // nicht die ganze Zuordnung — beim Ausrollen ist immer ein System
        // einen Schritt weiter.
        config(['inbox.labels.sources' => ['crm' => 'CRM', 'kaputt' => ['nein'], 7 => 'Zahl']]);

        expect(Systemnamen::fremde('inbox'))->toBe(['crm' => 'CRM']);
    });
});
