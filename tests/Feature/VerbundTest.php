<?php

use Peppermint\AiBrainBridge\Exceptions\AiBrainBridgeException;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Akte;
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

/*
 * ----------------------------------------------------------------------------
 * Die Akte (#9682)
 * ----------------------------------------------------------------------------
 */

describe('Einen Skalar aus der Nutzlast lesen', function (): void {
    it('findet einen Wert, der keine Liste ist, in jeder Huelle', function (string $huelle): void {
        $nutzlast = ['kennung' => 'D-10417', 'neu' => true];

        $antwort = $huelle === '' ? $nutzlast : [$huelle => $nutzlast];

        expect(Huelle::wert($antwort, 'kennung'))->toBe('D-10417');
    })->with(['', 'data', 'structuredContent', 'content', 'result', 'antwort']);

    it('ist noetig, weil schaelen() eine Huelle nur an einer LISTE erkennt', function (): void {
        // Der Fehlgriff, den dieser Weg verhindert: `schaelen()` prueft
        // `is_array($kandidat[$erwartet])`. Bei `{"geloest": true}` ist der
        // Schluessel da, sein Wert aber kein Array — die Huelle kommt leer
        // zurueck, und der Aufrufer liest `false`, obwohl das Werkzeug
        // `true` geliefert hat. Eine beruhigende Antwort statt eines Fehlers.
        expect(Huelle::schaelen(['data' => ['geloest' => true]], 'geloest'))->toBe([]);
        expect(Huelle::wert(['data' => ['geloest' => true]], 'geloest'))->toBeTrue();
    });

    it('unterscheidet ein ausdrueckliches null von einem fehlenden Schluessel', function (): void {
        // `hinweis: null` ist eine Aussage des Werkzeugs („kein Hinweis"),
        // kein fehlender Schluessel. Mit isset() waere beides dasselbe und
        // der Ersatzwert haette die Aussage uebertoent.
        expect(Huelle::wert(['hinweis' => null], 'hinweis', 'ersatz'))->toBeNull();
        expect(Huelle::wert(['anderes' => 1], 'hinweis', 'ersatz'))->toBe('ersatz');
    });

    it('nimmt keinen Wert, der eine Liste ist', function (): void {
        // Listen gehoeren zu schaelen()/zeilen(). Gaebe wert() sie heraus,
        // haette dieselbe Nutzlast zwei Wege mit zwei Ergebnissen.
        expect(Huelle::wert(['verweise' => [['art' => 'auftrag']]], 'verweise', 'ersatz'))->toBe('ersatz');
    });
});

describe('Die Akte binden und loesen', function (): void {
    it('nennt dem Brain die eigene Art zuerst und die fremde Herkunft dazu', function (): void {
        AiBrain::shouldReceive('call')
            ->once()
            ->with('dossier-link-tool', [
                'a_art' => 'auftrag',
                'a_kennung' => '7',
                'b_art' => 'projekt',
                'b_kennung' => '884',
                'b_system' => 'peppermint-manager',
            ])
            ->andReturn(['kennung' => 'D-10417', 'neu' => true, 'verweise' => []]);

        expect(Akte::binden('auftrag', '7', 'projekt', '884', 'peppermint-manager'))
            ->toBe('D-10417');
    });

    it('laesst die eigene Herkunft weg — die setzt das Brain aus dem Zugang', function (): void {
        // Ein System weiss nicht, unter welchem Kuerzel es anderswo gefuehrt
        // wird (Wiki #595). Duerfte es sich selbst eines geben, stuenden in
        // drei Oberflaechen drei Woerter fuer dasselbe System.
        AiBrain::shouldReceive('call')
            ->once()
            ->with('dossier-link-tool', Mockery::on(
                fn (array $argumente): bool => ! array_key_exists('a_system', $argumente)
            ))
            ->andReturn(['kennung' => 'D-10417']);

        Akte::binden('auftrag', '7', 'projekt', '884', 'peppermint-manager');
    });

    it('gibt die eigene Adresse als a_adresse mit', function (): void {
        AiBrain::shouldReceive('call')
            ->once()
            ->with('dossier-link-tool', Mockery::on(
                fn (array $argumente): bool => ($argumente['a_adresse'] ?? null) === '/orders/7'
            ))
            ->andReturn(['kennung' => 'D-10417']);

        Akte::binden('auftrag', '7', 'projekt', '884', 'peppermint-manager', '/orders/7');
    });

    it('sendet NIE eine Adresse fuer den fremden Datensatz', function (): void {
        /*
         * Es gibt mit Absicht kein `b_adresse` (#9815). Wer einen fremden
         * Datensatz mitbindet, kennt dessen Adresse nicht und koennte sie nur
         * raten — genau das soll die Adresse am Verweis verhindern. Das Brain
         * lehnt eine fremde Adresse ohnehin ab; dieser Test haelt fest, dass
         * sie hier gar nicht erst entsteht.
         */
        AiBrain::shouldReceive('call')
            ->once()
            ->with('dossier-link-tool', Mockery::on(
                fn (array $argumente): bool => ! array_key_exists('b_adresse', $argumente)
            ))
            ->andReturn(['kennung' => 'D-10417']);

        Akte::binden('auftrag', '7', 'projekt', '884', 'peppermint-manager', '/orders/7');
    });

    it('laesst a_adresse weg, wenn keine genannt ist, statt null zu senden', function (): void {
        // Sonst traegt das Brain beim Nachtragen eine leere Adresse ein und
        // haelt den Verweis fuer versorgt.
        AiBrain::shouldReceive('call')
            ->once()
            ->with('dossier-link-tool', Mockery::on(
                fn (array $argumente): bool => ! array_key_exists('a_adresse', $argumente)
            ))
            ->andReturn(['kennung' => 'D-10417']);

        Akte::binden('auftrag', '7', 'projekt', '884', 'peppermint-manager');
    });

    it('laesst b_system weg, wenn keines genannt ist, statt null zu senden', function (): void {
        AiBrain::shouldReceive('call')
            ->once()
            ->with('dossier-link-tool', Mockery::on(
                fn (array $argumente): bool => ! array_key_exists('b_system', $argumente)
            ))
            ->andReturn(['kennung' => 'D-10417']);

        Akte::binden('auftrag', '7', 'projekt', '884');
    });

    it('wirft, wenn die Antwort keine Kennung nennt', function (): void {
        // Das ist der Punkt, an dem ein stiller Fehlschlag entstehen wuerde:
        // Gaebe der Helfer hier null zurueck, liesse sich das als „Bruecke
        // nicht eingerichtet" lesen — und niemand suchte den fehlenden
        // Verweis.
        AiBrain::shouldReceive('call')->andReturn(['irgendwas' => 'anderes']);

        expect(fn () => Akte::binden('auftrag', '7', 'projekt', '884'))
            ->toThrow(AiBrainBridgeException::class);
    });

    it('gibt den Fehlschlag weiter, statt ihn zu schlucken', function (): void {
        // Die Wiederholung gehoert in die Warteschlange des Produkts — nur
        // dort ist bekannt, ob es eine gibt. Ein Helfer, der schluckt, macht
        // aus einem fehlenden Verweis ein stilles Ereignis.
        AiBrain::shouldReceive('call')->andThrow(new AiBrainBridgeException('Brain weg'));

        expect(fn () => Akte::binden('auftrag', '7', 'projekt', '884'))
            ->toThrow(AiBrainBridgeException::class, 'Brain weg');
    });

    it('loest einen Verweis und liest das Ergebnis als Wahrheitswert', function (): void {
        AiBrain::shouldReceive('call')
            ->once()
            ->with('dossier-unlink-tool', [
                'art' => 'projekt',
                'kennung' => '884',
                'system' => 'peppermint-manager',
            ])
            ->andReturn(['geloest' => true, 'akte' => 'D-10417', 'verweise_uebrig' => 1]);

        expect(Akte::loesen('projekt', '884', 'peppermint-manager'))->toBeTrue();
    });

    it('liest „es gab keinen Verweis" als Aussage, nicht als Fehlschlag', function (): void {
        AiBrain::shouldReceive('call')
            ->andReturn(['geloest' => false, 'grund' => 'Zu diesem Datensatz gab es keinen Verweis.']);

        expect(Akte::loesen('projekt', '884'))->toBeFalse();
    });

    it('sagt frueh und laut ab bei einer Art, die die Akte nicht fuehrt', function (): void {
        // Belege haengen an ihrem Auftrag, Aufgaben an ihrem oertlichen
        // Datensatz. Ohne diese Pruefung landet der Fehlertext des Brain in
        // einem Job-Versuch statt beim Entwickler.
        AiBrain::shouldReceive('call')->never();

        expect(fn () => Akte::binden('ticket', '25', 'auftrag', '7'))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => Akte::loesen('beleg', 'RE1234'))
            ->toThrow(InvalidArgumentException::class);
    });
});
