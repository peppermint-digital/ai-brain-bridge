<?php

namespace Peppermint\AiBrainBridge\Health;

/**
 * Entscheidet, ob dieses Paket die App-Health meldet (K7, AI Brain #5239).
 *
 * Eigene Klasse statt einer Bedingung im ServiceProvider, weil genau diese
 * Entscheidung geprüft werden muss: Ein Provider lässt sich schlecht befragen,
 * nachdem er gebootet hat.
 */
class HealthReporting
{
    /** Voll qualifizierter Name des abgelösten Pakets. */
    public const ALTES_PAKET = \AiBrain\Connector\ConnectorServiceProvider::class;

    /**
     * Läuft die Health-Meldung über dieses Paket?
     *
     * Zwei Gründe für ein Nein:
     *
     * 1. Sie ist abgeschaltet.
     * 2. Das abgelöste Paket `ai-brain/laravel-connector` ist noch installiert.
     *    Dann meldet jenes weiter, und dieses tritt zurück — sonst liefe die
     *    Meldung während der Umstellung doppelt. Zwei Meldungen derselben App
     *    im Fünf-Minuten-Takt sehen in AI Brain aus wie ein flatternder Dienst,
     *    nicht wie ein Umbau.
     */
    public static function aktiv(): bool
    {
        if (class_exists(self::ALTES_PAKET)) {
            return false;
        }

        return (bool) config('ai-brain-bridge.health.enabled');
    }
}
