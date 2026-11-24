<?php

namespace Peppermint\AiBrainBridge\Tests;

/**
 * Konto mit AI Brain verbinden, OHNE dass „Mit AI Brain anmelden" an ist.
 *
 * Das ist der Zustand nach der Abschaltung der gemeinsamen Anmeldung: Das
 * Produkt meldet seine Personen selbst an, die Verbindung zur Person in AI
 * Brain soll trotzdem herstellbar bleiben. Die Routen entstehen beim Boot,
 * deshalb ein eigener Ordner mit eigener TestCase.
 */
abstract class VerbindenTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai-brain-bridge.base_url', 'https://brain.test');
        $app['config']->set('ai-brain-bridge.login.enabled', false);
        $app['config']->set('ai-brain-bridge.login.connect', true);
        $app['config']->set('ai-brain-bridge.login.client_id', 'login-client');
        $app['config']->set('ai-brain-bridge.login.client_secret', 'geheim');
        $app['config']->set('ai-brain-bridge.login.connect_after', '/einstellungen/ai-brain');
        $app['config']->set('auth.providers.users.model', \VerbindenTestUser::class);
    }
}
