<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Middleware\ResolveEnvironmentFromPath;
use App\Http\Props\Prop;
use App\Platform\Realtime\ChannelAuthorizer;
use App\Platform\Realtime\LiveUpdates;
use Illuminate\Http\Request;
use Throwable;

/**
 * Where a page connects for live updates, and the channels it may listen on.
 *
 * Null — and the page simply does not connect — when live updates are off, nobody is
 * signed in, or nothing on this page has a channel. The channel list is decided by the
 * same {@see ChannelAuthorizer} that signs the subscriptions, so a page is never offered
 * a channel its authorization endpoint would refuse.
 *
 * `host` null means "the host this page came from": the socket is then same-origin.
 * `authUrl` is a path on this host, inside the gates of the pages that listen.
 */
final readonly class RealtimeProps implements Prop
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $key,
        public ?string $host,
        public int $port,
        public string $scheme,
        public string $authUrl,
        public array $channels,
    ) {}

    public static function from(Request $request, ChannelAuthorizer $authorizer): ?self
    {
        if (! LiveUpdates::enabled()) {
            return null;
        }

        $channels = $authorizer->channelsFor($request);
        $route = self::authRoute($request);

        if ($channels === [] || $route === null) {
            return null;
        }

        $host = config('realtime.client.host');
        $scheme = config('realtime.client.scheme');

        return new self(
            key: config()->string('broadcasting.connections.reverb.key'),
            host: is_string($host) && trim($host) !== '' ? trim($host) : null,
            port: config()->integer('realtime.client.port', 443),
            scheme: $scheme === 'http' ? 'http' : 'https',
            authUrl: route($route, [], false),
            channels: $channels,
        );
    }

    /** The authorization endpoint mounted in this page's own route group. */
    private static function authRoute(Request $request): ?string
    {
        $name = (string) $request->route()?->getName();

        $route = match (true) {
            $request->attributes->has(ResolveEnvironmentFromPath::ATTRIBUTE) => 'environment.realtime.auth',
            str_starts_with($name, 'orgs.') => 'orgs.realtime.auth',
            default => 'realtime.auth',
        };

        try {
            route($route, [], false);
        } catch (Throwable) {
            return null;
        }

        return $route;
    }

    /**
     * @return array{key: string, host: string|null, port: int, scheme: string, authUrl: string, channels: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'host' => $this->host,
            'port' => $this->port,
            'scheme' => $this->scheme,
            'authUrl' => $this->authUrl,
            'channels' => $this->channels,
        ];
    }
}
