<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Favoriten der Umschaltleiste im Produkt selbst (pm #10219).
 *
 * Nur im Betrieb `switcher.pins.store = local` geladen. Bis dahin lagen die
 * Favoriten in AI Brain; dort heisst die Tabelle gleich und hat dieselben
 * Spalten, damit die Uebernahme Zeile fuer Zeile abbildet.
 *
 * `url_hash` statt eines Index ueber `url`: Eine Adresse darf 2048 Zeichen
 * lang sein, und MySQL indiziert in utf8mb4 hoechstens 3072 Bytes, also 768
 * Zeichen. Eindeutig je Person und Adresse bleibt es ueber den Fingerabdruck.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('switcher_pins')) {
            return;
        }

        $personen = (string) config('ai-brain-bridge.switcher.pins.personen_tabelle', 'users');

        Schema::create('switcher_pins', function (Blueprint $table) use ($personen): void {
            $table->id();
            $table->foreignId('user_id')->constrained($personen, 'id', 'switcher_pins_user_fk')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('label');
            $table->string('symbol', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'url_hash'], 'switcher_pins_user_url_unique');
            $table->index(['user_id', 'position'], 'switcher_pins_user_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switcher_pins');
    }
};
