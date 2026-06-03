<?php

use Peppermint\AiBrainBridge\Events\EventSignature;

it('signs and verifies a payload', function () {
    $body = '{"type":"offer.created"}';
    $secret = 'shh';

    $sig = EventSignature::sign($body, $secret);

    expect($sig)->toStartWith('sha256=')
        ->and(EventSignature::verify($body, $sig, $secret))->toBeTrue();
});

it('rejects a tampered payload or wrong secret', function () {
    $sig = EventSignature::sign('{"a":1}', 'right');

    expect(EventSignature::verify('{"a":2}', $sig, 'right'))->toBeFalse()
        ->and(EventSignature::verify('{"a":1}', $sig, 'wrong'))->toBeFalse()
        ->and(EventSignature::verify('{"a":1}', null, 'right'))->toBeFalse();
});
