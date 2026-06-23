<?php

namespace Peppermint\AiBrainBridge\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Peppermint\AiBrainBridge\AiBrainBridgeServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AiBrainBridgeServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // In-Memory-DB für die Peer-Connector-Tabellen (Phase 3).
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Fester App-Key (encrypted-Casts brauchen ihn; RNG ist in der Sandbox geblockt).
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
