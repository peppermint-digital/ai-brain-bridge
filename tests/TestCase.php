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
}
