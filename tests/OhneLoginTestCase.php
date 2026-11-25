<?php

namespace Peppermint\AiBrainBridge\Tests;

/**
 * Ein Produkt mit Umschaltleiste, aber OHNE Anmeldung ueber AI Brain
 * (pm #10190: Peppermint Manager in Produktion, AI_BRAIN_LOGIN=false).
 *
 * Eigene Testklasse, weil die Routen der Leiste beim Hochfahren registriert
 * werden — ein spaeteres config()->set() erreicht sie nicht mehr.
 */
abstract class OhneLoginTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai-brain-bridge.base_url', 'https://brain.test');
        $app['config']->set('ai-brain-bridge.login.enabled', false);
        $app['config']->set('ai-brain-bridge.switcher.enabled', true);
        // Favoriten ausdruecklich in AI Brain: der Stand bis pm #10219. Seither
        // liegen sie ohne Anmeldeweg nach Vorgabe im Produkt — das pruefen die
        // Tests unter tests/Favoriten.
        $app['config']->set('ai-brain-bridge.switcher.pins.store', 'brain');
        $app['config']->set('ai-brain-bridge.oauth.client_id', 'maschinen-client');
        $app['config']->set('ai-brain-bridge.oauth.client_secret', 'maschinen-geheimnis');
        $app['config']->set('auth.providers.users.model', \OhneLoginNutzer::class);
    }
}
