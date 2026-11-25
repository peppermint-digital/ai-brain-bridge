<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Mit gemeinsamer Anmeldung kommt jede Person aus AI Brain — sie ist damit
 * verbunden, auch ohne Spalte (pm #10219). So bleibt in solchen Produkten
 * alles, wie es war.
 */
beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    $this->person = LoginTestUser::create(['name' => 'Bastian', 'email' => 'bastian@example.test']);
});

it('haelt mit gemeinsamer Anmeldung jede angemeldete Person fuer verbunden', function () {
    expect(Gate::forUser($this->person)->allows('ai-brain.verbunden'))->toBeTrue()
        ->and(AiBrain::istVerbunden($this->person))->toBeTrue()
        ->and(AiBrain::istVerbunden(null))->toBeFalse();
});
