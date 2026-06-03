<?php

namespace Peppermint\AiBrainBridge\Events;

/**
 * HMAC-SHA256-Signatur für den Event-Bus. Header: X-Signature: sha256=<hex>.
 */
class EventSignature
{
    public const HEADER = 'X-Signature';

    public static function sign(string $rawBody, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $secret);
    }

    public static function verify(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(self::sign($rawBody, $secret), $signature);
    }
}
