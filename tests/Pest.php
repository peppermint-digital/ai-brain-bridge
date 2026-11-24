<?php

use Peppermint\AiBrainBridge\Tests\LoginTestCase;
use Peppermint\AiBrainBridge\Tests\TestCase;
use Peppermint\AiBrainBridge\Tests\VerbindenTestCase;

uses(TestCase::class)->in('Feature');

// Eigener Ordner, weil die Anmelde-Routen (AI Brain #5266) beim Boot entstehen:
// Die Freischaltung muss vor dem Hochfahren stehen, also in einer eigenen
// TestCase — und Pest lässt pro Ordner nur eine zu.
uses(LoginTestCase::class)->in('Login');

// Konto verbinden ohne gemeinsame Anmeldung — eigener Boot-Zustand.
uses(VerbindenTestCase::class)->in('Verbinden');
