<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connector-Plattform v2 (Spec #249 §10) — additive Härtung der Peer-Verbindungen:
 *  - `scopes`           granulare Berechtigung pro Verbindung (#2582). NULL/leer = voller
 *                       Zugriff (rückwärtskompatibel zum bisherigen Verhalten).
 *  - `issued_token_ref` issuer-private Referenz auf den ausgestellten nativen api.token,
 *                       damit `revoke()` ihn wirklich killen kann (#2583).
 *  - `replaced_by_id`   Rotations-Lineage: alter Connector zeigt auf seinen Nachfolger (#2583).
 *  - `peer_claim_codes.issuer_ref` dieselbe Referenz, bis der Code eingelöst wird
 *                       (wird NICHT im Bundle an den Peer ausgeliefert).
 *
 * Rein additiv, alle Spalten nullable → kein Datenverlust, bestehende Verbindungen laufen weiter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peer_connectors', function (Blueprint $table) {
            $table->json('scopes')->nullable()->after('openapi_url');
            $table->text('issued_token_ref')->nullable()->after('scopes');
            $table->unsignedBigInteger('replaced_by_id')->nullable()->after('issued_token_ref');
        });

        Schema::table('peer_claim_codes', function (Blueprint $table) {
            $table->text('issuer_ref')->nullable()->after('bundle');
        });
    }

    public function down(): void
    {
        Schema::table('peer_connectors', function (Blueprint $table) {
            $table->dropColumn(['scopes', 'issued_token_ref', 'replaced_by_id']);
        });

        Schema::table('peer_claim_codes', function (Blueprint $table) {
            $table->dropColumn('issuer_ref');
        });
    }
};
