<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Die Peer-Tabellen abräumen (AI Brain #5431).
 *
 * ## Warum jetzt
 *
 * Die Peer-Mechanik ist seit #5400 aus dem Paket und aus allen Produkten
 * entfernt. Es gibt keine Klasse mehr, die aus diesen Tabellen liest — die
 * Zeilen sind damit unerreichbar, nicht bloß ungenutzt.
 *
 * Stehen bleiben sollten sie trotzdem nicht: Wer in einem halben Jahr eine
 * Tabelle voller Verbindungen mit Status `active` sieht, schließt daraus das
 * Falsche.
 *
 * ## Was verloren geht
 *
 * Gemessen vor dem Entfernen (13.09.2026): je vier Verbindungen und zwei
 * Anspruchscodes in Verwaltung, CRM und Manager; im Crewtex-Shop leere
 * Tabellen; in AI Brain gar keine. Inhalt sind Adressen und Token einer
 * Mechanik, die es nicht mehr gibt.
 *
 * **Es gibt keinen Rückweg.** Die Token lassen sich nicht wiederherstellen.
 * Das ist vertretbar, weil sie zu nichts mehr gehören — aber `down()` kann
 * die Tabellen nur leer zurückgeben, nicht ihren Inhalt. Deshalb legt es sie
 * auch nicht wieder an: Eine leere Hülle vorzutäuschen wäre schlechter als
 * ein ehrliches „das ist weg".
 *
 * ## dropIfExists, nicht drop
 *
 * Das Paket steckt in zwölf Anwendungen. Nicht in allen sind die Tabellen je
 * entstanden — in AI Brain etwa nicht. Ein `drop` würde dort abbrechen und
 * den Deploy mitnehmen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('peer_claim_codes');
        Schema::dropIfExists('peer_connectors');
    }

    public function down(): void
    {
        // Absichtlich leer. Siehe oben: Die Tabellen liessen sich anlegen, ihr
        // Inhalt nicht. Ein `down()`, das leere Tabellen erzeugt, sieht aus
        // wie eine Rücknahme und ist keine.
    }
};
