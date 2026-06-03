<?php

namespace Peppermint\AiBrainBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Peppermint\AiBrainBridge\Events\EventSignature;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Prüft die HMAC-Signatur eingehender AI-Brain-Webhooks (Schiene 2).
 */
class VerifyAiBrainSignature
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('ai-brain-bridge.events.secret', '');
        $signature = $request->header(EventSignature::HEADER);

        if (! EventSignature::verify($request->getContent(), $signature, $secret)) {
            throw new AccessDeniedHttpException('Invalid AI Brain signature.');
        }

        return $next($request);
    }
}
