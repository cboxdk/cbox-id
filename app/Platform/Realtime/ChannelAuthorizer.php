<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

use App\Http\Middleware\ResolveEnvironmentFromPath;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Impersonation;
use Illuminate\Http\Request;

/**
 * May the person behind this request listen on this channel?
 *
 * Asked INSIDE the route group of the pages that listen on it, so the gates have already
 * run and this only compares what they established with what the browser asked for:
 *
 *  - an ENVIRONMENT channel on the environment console's own path: the environment that
 *    path resolved to (`env.path` authorized the person for it), and somebody who may at
 *    least read that console — a read-only product role included, as on its pages;
 *  - an ORGANIZATION channel on the organization console: the organization this request is
 *    acting in (`/orgs/{org}` binds the one in the address after checking the membership;
 *    elsewhere it is the session's), and only for its owners and administrators;
 *  - a SUBJECT channel: the signed-in person's own, and nobody else's.
 *
 * Nothing while impersonating. An impersonation is a time-boxed, audited look at a
 * person's pages through the gates on every request; a socket outlives that box, so it
 * is not part of what an impersonation may do.
 */
final readonly class ChannelAuthorizer
{
    public function __construct(
        private CurrentUser $user,
        private EnvironmentAdminAuth $environmentAdmin,
        private Impersonation $impersonation,
    ) {}

    public function allows(Request $request, string $channel): bool
    {
        $parsed = Channels::parse($channel);

        if ($parsed === null || $this->impersonation->isImpersonating()) {
            return false;
        }

        return match ($parsed['kind']) {
            Channels::ENVIRONMENT => $this->environment($request) === $parsed['id'] && $this->environmentAdmin->canRead(),
            Channels::ORGANIZATION => $this->user->check()
                && $this->user->organizationId() === $parsed['id']
                && $this->user->isAdmin(),
            Channels::SUBJECT => $this->user->check() && $this->user->id() === $parsed['id'],
            default => false,
        };
    }

    /**
     * The channels a page drawn for this request may listen on, by the same rules —
     * what the shared `realtime` prop offers.
     *
     * @return list<string>
     */
    public function channelsFor(Request $request): array
    {
        if ($this->impersonation->isImpersonating()) {
            return [];
        }

        $environment = $this->environment($request);

        if ($environment !== null) {
            return $this->environmentAdmin->canRead() ? [Channels::environment($environment)] : [];
        }

        if (! $this->user->check() || $this->user->id() === '') {
            return [];
        }

        $channels = [Channels::subject($this->user->id())];
        $organization = $this->user->organizationId();

        if ($organization !== null && $this->user->isAdmin()) {
            $channels[] = Channels::organization($organization);
        }

        return array_values(array_filter($channels, static fn (string $c): bool => Channels::parse(Channels::private($c)) !== null));
    }

    private function environment(Request $request): ?string
    {
        $environment = $request->attributes->get(ResolveEnvironmentFromPath::ATTRIBUTE);

        return is_string($environment) && $environment !== '' ? $environment : null;
    }
}
