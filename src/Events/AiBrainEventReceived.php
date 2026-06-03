<?php

namespace Peppermint\AiBrainBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Laravel-Event, das gefeuert wird, wenn AI Brain ein (signiertes) Event an
 * dieses Produkt schickt. Produkte registrieren ganz normale Listener — oder
 * nutzen AiBrain::on($type, $cb) als Komfort-Wrapper.
 */
class AiBrainEventReceived
{
    use Dispatchable;

    public function __construct(public Event $event) {}
}
