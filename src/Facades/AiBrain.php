<?php

namespace Peppermint\AiBrainBridge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array call(string $tool, array $arguments = [])
 * @method static array gateway(string $capability, array $arguments = [], ?string $product = null, ?string $actingAs = null, ?string $channel = null)
 * @method static \Peppermint\AiBrainBridge\AiBrainManager resolveActingUserUsing(?callable $resolver)
 * @method static mixed asService(callable $callback)
 * @method static array actingUserHeaders(?string $behauptet = null, ?string $channel = null)
 * @method static \Peppermint\AiBrainBridge\AiBrainManager resolveInboundUserUsing(?callable $resolver)
 * @method static mixed resolveInboundUser(string $email)
 * @method static ?string actingSecret()
 * @method static ?string inboundChannel()
 * @method static \Peppermint\AiBrainBridge\Mcp\McpClient brain()
 * @method static \Peppermint\AiBrainBridge\Mcp\McpClient mcp(string $url)
 * @method static \Peppermint\AiBrainBridge\Channels\ChannelClient channel(string $channel)
 * @method static array channels()
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
