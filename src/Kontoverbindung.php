<?php

namespace Peppermint\AiBrainBridge;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Ist dieses Konto mit einem Konto in AI Brain verbunden? (pm #10219)
 *
 * EIN Schalter fuer alle Pakete. Funktionen, die es nur mit AI Brain gibt,
 * fragen hier — und ohne Verbindung gibt es sie nicht. Bastian, 25.11.2026:
 * „Wer keinen AI-Brain nutzt, der hat die Funktion einfach nicht."
 *
 * ## Wie andere Pakete fragen
 *
 *     Gate::allows('ai-brain.verbunden')          // ohne Abhaengigkeit auf die Bruecke
 *     $person->can('ai-brain.verbunden')
 *     @can('ai-brain.verbunden') … @endcan
 *     AiBrain::istVerbunden($person)              // Pakete, die die Bruecke ohnehin nutzen
 *
 * Ist die Bruecke nicht installiert, ist die Faehigkeit nicht definiert, und
 * Laravel antwortet `false`. Ein Paket, das ueber das Gate fragt, braucht die
 * Bruecke also nicht als Abhaengigkeit.
 *
 * ## Warum die Frage VOR dem Abruf steht
 *
 * Ohne handelnde Person ruft die Bruecke AI Brain als Dienstkonto auf. Wer
 * erst die Antwort filtert, hat die Daten schon geholt. Jede Funktion, die
 * Persoenliches aus AI Brain zeigt, fragt deshalb hier, bevor sie anklopft.
 *
 * ## Wer entscheidet
 *
 * Die Vorgabe ist {@see SpaltenKontoverbindung}. Ein Produkt mit eigener Regel
 * bindet den Vertrag selbst:
 *
 *     $this->app->bind(Kontoverbindung::class, MeineRegel::class);
 */
interface Kontoverbindung
{
    public function istVerbunden(?Authenticatable $person): bool;

    /**
     * Mit welcher Adresse die Bruecke fuer diese Person in AI Brain auftritt.
     *
     * Bei einer verbundenen Person die ihres AI-Brain-Kontos (dort darf sie
     * anders heissen als hier), sonst die eigene. `null` ohne Person.
     */
    public function adresse(?Authenticatable $person): ?string;
}
