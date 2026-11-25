<?php

use Peppermint\AiBrainBridge\Tests\FavoritenTestCase;
use Peppermint\AiBrainBridge\Tests\LoginTestCase;
use Peppermint\AiBrainBridge\Tests\OhneLoginTestCase;
use Peppermint\AiBrainBridge\Tests\TestCase;
use Peppermint\AiBrainBridge\Tests\VerbindenTestCase;

uses(TestCase::class)->in('Feature');

// Eigener Ordner, weil die Anmelde-Routen (AI Brain #5266) beim Boot entstehen:
// Die Freischaltung muss vor dem Hochfahren stehen, also in einer eigenen
// TestCase — und Pest lässt pro Ordner nur eine zu.
uses(LoginTestCase::class)->in('Login');

uses(OhneLoginTestCase::class)->in('OhneLogin');

// Konto verbinden ohne gemeinsame Anmeldung — eigener Boot-Zustand.
uses(VerbindenTestCase::class)->in('Verbinden');

// Favoriten im Produkt, ohne gemeinsame Anmeldung (pm #10219).
uses(FavoritenTestCase::class)->in('Favoriten');
