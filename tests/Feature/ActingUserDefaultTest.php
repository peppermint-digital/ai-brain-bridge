<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Mcp\McpClient;

/**
 * Sicherer Default (ab 1.2): ohne Konfiguration schickt ein Produkt den
 * EINGELOGGTEN User als handelnde Person mit. Vorher bedeutete „kein Resolver"
 * stillschweigend „niemand" — jeder Aufruf lief userlos, und niemandem fiel es
 * auf. Wer ohne Person handeln will, muss das jetzt hinschreiben: asService().
 */
class ActingUserDefaultStub implements Authenticatable
{
    public function __construct(public string $email) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return 1;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}

beforeEach(function () {
    config()->set('ai-brain-bridge.base_url', 'https://brain.test');
    config()->set('ai-brain-bridge.oauth.client_id', 'cid');
    config()->set('ai-brain-bridge.oauth.client_secret', 'csec');

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        $body = json_decode($request->body(), true);

        if (($body['method'] ?? '') === 'initialize') {
            return Http::response(
                ['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => ['protocolVersion' => '2025-06-18']],
                200,
                ['Mcp-Session-Id' => 'sess-1'],
            );
        }

        return Http::response([
            'jsonrpc' => '2.0',
            'id' => $body['id'] ?? 1,
            'result' => ['structuredContent' => ['ok' => true]],
        ]);
    });
});

function actingHeaderOf(): ?string
{
    $found = null;
    Http::assertSent(function ($request) use (&$found) {
        if (str_contains($request->url(), '/mcp/brain') && $request->hasHeader(McpClient::ACTING_USER_HEADER)) {
            $found = $request->header(McpClient::ACTING_USER_HEADER)[0];
        }

        return true;
    });

    return $found;
}

it('sends the authenticated user as acting person without any configuration', function () {
    Auth::setUser(new ActingUserDefaultStub('anna@example.test'));

    AiBrain::call('list-projects-tool');

    expect(actingHeaderOf())->toBe('anna@example.test');
});

it('sends no acting person in a background job — nobody is logged in', function () {
    // Kein Auth::setUser: der Default findet niemanden. Solche Aufrufe gehören
    // ausdrücklich in asService(), damit man sie im Code sieht.
    AiBrain::call('list-projects-tool');

    expect(actingHeaderOf())->toBeNull();
});

it('suppresses the acting person inside asService — even with a logged-in user', function () {
    Auth::setUser(new ActingUserDefaultStub('anna@example.test'));

    AiBrain::asService(fn () => AiBrain::call('list-projects-tool'));

    expect(actingHeaderOf())->toBeNull();
});

it('restores the acting person after asService — no global leak', function () {
    Auth::setUser(new ActingUserDefaultStub('anna@example.test'));

    AiBrain::asService(fn () => null);
    AiBrain::call('list-projects-tool');

    expect(actingHeaderOf())->toBe('anna@example.test');
});

it('lets an explicit resolver win over the default', function () {
    Auth::setUser(new ActingUserDefaultStub('anna@example.test'));
    AiBrain::resolveActingUserUsing(fn () => 'mapped@example.test');

    AiBrain::call('list-projects-tool');

    expect(actingHeaderOf())->toBe('mapped@example.test');
});

it('signs the assertion when an event secret is configured', function () {
    config()->set('ai-brain-bridge.events.secret', 'shared-secret');
    Auth::setUser(new ActingUserDefaultStub('anna@example.test'));

    AiBrain::call('list-projects-tool');

    $expected = 'sha256='.hash_hmac('sha256', 'anna@example.test', 'shared-secret');

    Http::assertSent(fn ($request) => ! $request->hasHeader(McpClient::ACTING_USER_HEADER)
        || $request->header(McpClient::ACTING_SIG_HEADER)[0] === $expected);
});
