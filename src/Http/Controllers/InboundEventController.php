<?php

namespace Peppermint\AiBrainBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Peppermint\AiBrainBridge\Events\AiBrainEventReceived;
use Peppermint\AiBrainBridge\Events\Event;

/**
 * Empfängt signierte Events von AI Brain, dedupliziert per Idempotency-Key
 * und feuert AiBrainEventReceived. Signatur wird per Middleware geprüft.
 */
class InboundEventController
{
    public function __invoke(Request $request): JsonResponse
    {
        $event = Event::fromArray((array) $request->json()->all());

        $cacheKey = 'ai_brain_bridge.inbound.'.$event->idempotencyKey;

        if (Cache::has($cacheKey)) {
            return response()->json(['ok' => true, 'dedup' => true]);
        }

        Cache::put($cacheKey, true, (int) config('ai-brain-bridge.inbound.idempotency_ttl', 86400));

        AiBrainEventReceived::dispatch($event);

        return response()->json(['ok' => true]);
    }
}
