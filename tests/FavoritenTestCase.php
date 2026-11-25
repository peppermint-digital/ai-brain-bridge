<?php

namespace Peppermint\AiBrainBridge\Tests;

/**
 * Favoriten im Produkt selbst (pm #10219) — so wie Peppermint Manager in
 * Produktion: eigene Anmeldung (AI_BRAIN_LOGIN=false), Leiste an, Favoriten
 * nach Vorgabe lokal.
 *
 * Eigene Testklasse, weil Routen und Migrationen beim Hochfahren entstehen.
 */
abstract class FavoritenTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Wie MySQL in Produktion: Fremdschluessel gelten.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);

        $app['config']->set('app.url', 'https://pm.test');
        $app['config']->set('ai-brain-bridge.source', 'peppermint-manager');
        $app['config']->set('ai-brain-bridge.base_url', 'https://brain.test');
        $app['config']->set('ai-brain-bridge.login.enabled', false);
        $app['config']->set('ai-brain-bridge.switcher.enabled', true);
        $app['config']->set('ai-brain-bridge.oauth.client_id', 'maschinen-client');
        $app['config']->set('ai-brain-bridge.oauth.client_secret', 'maschinen-geheimnis');
        $app['config']->set('auth.providers.users.model', \FavoritenNutzer::class);
    }
}
