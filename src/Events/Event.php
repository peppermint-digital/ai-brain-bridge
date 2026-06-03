<?php

namespace Peppermint\AiBrainBridge\Events;

use Illuminate\Support\Str;

/**
 * Einheitliches Event-Envelope (Schiene 2 — async, beide Richtungen).
 */
class Event
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public array $payload,
        public string $source,
        public ?string $entityRef = null,
        public ?string $id = null,
        public ?string $idempotencyKey = null,
        public ?string $correlationId = null,
        public ?string $occurredAt = null,
    ) {
        $this->id ??= 'evt_'.Str::uuid()->toString();
        $this->idempotencyKey ??= $this->id;
        $this->occurredAt ??= now()->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'entity_ref' => $this->entityRef,
            'occurred_at' => $this->occurredAt,
            'idempotency_key' => $this->idempotencyKey,
            'correlation_id' => $this->correlationId,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            payload: (array) ($data['payload'] ?? []),
            source: (string) ($data['source'] ?? 'unknown'),
            entityRef: $data['entity_ref'] ?? null,
            id: $data['id'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? null,
            correlationId: $data['correlation_id'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
        );
    }
}
