<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peer-to-Peer-Konnektoren (Connector-Plattform Phase 3, Spec #249, Track B).
 *
 * Liegt im PRODUKT, nicht im Hub — Produkt↔Produkt funktioniert OHNE AI Brain.
 * Jedes Produkt kann Codes ausstellen (andere verbinden sich mit ihm) und
 * einlösen (es verbindet sich mit anderen). Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Von DIESEM Produkt ausgestellte, einmalig einlösbare Codes.
        Schema::create('peer_claim_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->text('bundle'); // encrypted: peer_slug, api_url, api_token, openapi_url
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        // Aktive Verbindungen — inbound (wer darf mich rufen) + outbound (mit wem ich verbunden bin).
        Schema::create('peer_connectors', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10); // inbound | outbound
            $table->string('peer_slug')->nullable();
            $table->string('api_url')->nullable();        // outbound: API-Basis des Peers
            $table->string('token_hash', 64)->nullable(); // inbound: Hash des ausgestellten Tokens
            $table->text('api_token')->nullable();         // outbound (encrypted): Token, mit dem WIR den Peer rufen
            $table->string('openapi_url')->nullable();
            $table->string('status', 12)->default('active'); // active | revoked
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['direction', 'status']);
            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peer_connectors');
        Schema::dropIfExists('peer_claim_codes');
    }
};
