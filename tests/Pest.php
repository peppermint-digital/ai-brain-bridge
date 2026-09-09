<?php

use Peppermint\AiBrainBridge\Tests\TestCase;

uses(TestCase::class)->in('Feature');

// Eigener Ordner, weil die Anmelde-Routen (AI Brain #5266) beim Boot entstehen:
// Die Freischaltung muss vor dem Hochfahren stehen, also in einer eigenen
// TestCase — und Pest lässt pro Ordner nur eine zu.
uses(\Peppermint\AiBrainBridge\Tests\LoginTestCase::class)->in('Login');
