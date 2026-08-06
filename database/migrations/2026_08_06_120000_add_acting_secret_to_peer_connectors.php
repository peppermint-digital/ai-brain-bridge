<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eigenes Signatur-Geheimnis je Peer-Verbindung (AI-Brain-Bug #540).
 *
 * Die Acting-User-Delegation zwischen zwei Produkten wurde bisher mit dem
 * Event-Secret der Brain-Anbindung signiert. Seit AI Brain pro Produkt ein
 * eigenes Event-Secret ausgibt, haben zwei Peers dort kein gemeinsames
 * Geheimnis mehr — jede Peer-Signatur schlug fehl, die behauptete Person wurde
 * verworfen, und beim Empfaenger endete jeder Schreibzugriff in 403.
 *
 * Das Geheimnis gehoert deshalb an die Verbindung, nicht an die Brain-Anbindung.
 * Eines je RICHTUNG: der Anrufer haelt es auf seinem outbound-Connector, der
 * Angerufene auf dem passenden inbound-Connector. Damit kollidieren Hin- und
 * Rueckrichtung nicht und jede Seite kann ihr eigenes rotieren.
 *
 * Additiv und nullable: bestehende Verbindungen laufen unveraendert weiter, bis
 * sie sich beim ersten signierten Aufruf selbst versorgen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peer_connectors', function (Blueprint $table) {
            $table->text('acting_secret')->nullable()->after('api_token');
        });
    }

    public function down(): void
    {
        Schema::table('peer_connectors', function (Blueprint $table) {
            $table->dropColumn('acting_secret');
        });
    }
};
