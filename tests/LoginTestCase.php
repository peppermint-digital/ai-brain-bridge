<?php

namespace Peppermint\AiBrainBridge\Tests;

/**
 * Die Anmelde-Routen entstehen beim Boot des Providers — die Freischaltung muss
 * deshalb stehen, bevor die Anwendung hochfährt (AI Brain #5266).
 */
abstract class LoginTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai-brain-bridge.base_url', 'https://brain.test');
        $app['config']->set('ai-brain-bridge.login.enabled', true);
        $app['config']->set('ai-brain-bridge.login.client_id', 'login-client');
        $app['config']->set('ai-brain-bridge.login.client_secret', 'geheim');
        $app['config']->set('ai-brain-bridge.login.after_login', '/dashboard');
        $app['config']->set('auth.providers.users.model', \LoginTestUser::class);
    }
}
