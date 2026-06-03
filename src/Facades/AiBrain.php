<?php

namespace Peppermint\AiBrainBridge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array call(string $tool, array $arguments = [])
 * @method static \Peppermint\AiBrainBridge\Mcp\McpClient brain()
 * @method static \Peppermint\AiBrainBridge\Mcp\McpClient mcp(string $url)
 * @method static \Peppermint\AiBrainBridge\Channels\ChannelClient channel(string $channel)
 * @method static bool emit(string $type, array $payload, ?string $entityRef = null, ?string $correlationId = null)
 * @method static void on(string $type, callable $handler)
 *
 * @see \Peppermint\AiBrainBridge\AiBrainManager
 */
class AiBrain extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Peppermint\AiBrainBridge\AiBrainManager::class;
    }
}
