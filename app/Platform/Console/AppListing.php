<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\AppKind;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * HOW THE APPS LIST IS CUT — into groups a person can find an app in, and by the kind of
 * app it is.
 *
 * The list was every client in the environment, newest first, as one column of identical
 * rows: sixteen first-party products, the machine clients behind them and every
 * organization's own apps interleaved by creation date. Nothing on a row said what the app
 * was for or where it lived, so finding one meant reading every client id.
 *
 * THE GROUPS are who owns an app, which is the question that decides what may be done to
 * it: the environment's own first-party apps (in the platform environment, the Cbox
 * services), the environment's other apps (integrations, machine clients, agents), and the
 * apps registered by or for one organization.
 *
 * THE KINDS are {@see AppKind}, read back from the grants by {@see self::kindFor()} — the
 * one reading the row's label, the filter and the app's own header all share, so a filter
 * can never disagree with the label on the row it returns.
 *
 * THE PRODUCTS are {@see self::parents()}: the helper clients behind a product nest under
 * it instead of standing beside it.
 *
 * The same for every environment: the platform environment and an organization's own
 * environment are cut by the same rules, because they are the same thing.
 */
final readonly class AppListing
{
    public const GROUP_FIRST_PARTY = 'first-party';

    public const GROUP_ENVIRONMENT = 'environment';

    public const GROUP_ORGANIZATIONS = 'organizations';

    /** @var list<string> */
    public const GROUPS = [self::GROUP_FIRST_PARTY, self::GROUP_ENVIRONMENT, self::GROUP_ORGANIZATIONS];

    /** @var list<string> the kinds offered as filters, in the order the form offers them */
    public const KINDS = ['web', 'spa', 'cli', 'service', 'agent', 'other'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_STALE = 'stale';

    public const STATUS_NEVER_USED = 'never-used';

    public const STATUS_DISABLED = 'disabled';

    /** How long without a token before an app reads as no longer in use. */
    public const STALE_AFTER_DAYS = 30;

    public function __construct(private AppCatalog $catalog) {}

    /** Which group an app belongs to. */
    public static function groupOf(Client $client): string
    {
        if ($client->organization_id !== null) {
            return self::GROUP_ORGANIZATIONS;
        }

        return $client->first_party ? self::GROUP_FIRST_PARTY : self::GROUP_ENVIRONMENT;
    }

    /**
     * The kind an app IS, read from its grants.
     *
     * An exact preset first ({@see AppKind::forClient()}). Real apps rarely stay exact: a
     * product's web app that also calls the API as itself and exchanges tokens carries
     * authorization_code, client_credentials, refresh_token and token-exchange, and filing
     * that under "Other" told the reader nothing they could use. So, failing an exact
     * match, the grant that brings people to the app decides: a browser sign-in is a web
     * app when it can keep a secret and a single-page or mobile app otherwise; then an
     * approval-asking agent, a device-flow CLI, a service. Only an app with none of those
     * is "Other". A public app that comes back to a loopback address is a CLI first.
     */
    public static function kindFor(Client $client): AppKind
    {
        $exact = AppKind::forClient($client);
        $grants = $client->grant_types ?? [];

        // A public app that signs in through a browser and comes back to this machine's
        // own address is a command-line tool (RFC 8252's native app), whatever preset its
        // grants happen to spell.
        if ($client->type === ClientType::Public && in_array('authorization_code', $grants, true) && self::returnsToLoopback($client)) {
            return AppKind::CliOrDevice;
        }

        if ($exact !== AppKind::Advanced) {
            return $exact;
        }

        if (in_array('authorization_code', $grants, true)) {
            return $client->type === ClientType::Confidential ? AppKind::WebApp : AppKind::SpaOrMobile;
        }

        return match (true) {
            in_array('urn:openid:params:grant-type:ciba', $grants, true) => AppKind::Agent,
            in_array('urn:ietf:params:oauth:grant-type:device_code', $grants, true) => AppKind::CliOrDevice,
            in_array('client_credentials', $grants, true) => AppKind::Service,
            default => AppKind::Advanced,
        };
    }

    /** The kind filter's value for an app: its kind, or `other`. */
    public static function kindOf(Client $client): string
    {
        $kind = self::kindFor($client);

        return $kind === AppKind::Advanced ? 'other' : $kind->value;
    }

    /** Whether every address the app is sent back to is this machine's own. */
    private static function returnsToLoopback(Client $client): bool
    {
        $uris = $client->redirect_uris ?? [];

        if ($uris === []) {
            return false;
        }

        foreach ($uris as $uri) {
            $host = parse_url($uri, PHP_URL_HOST);

            if (! in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What a row says about the app's use: switched off, never used, not used for a month,
     * or in use.
     *
     * Switched off is an app that authenticates with a shared secret and holds no live one:
     * every token request it makes is refused, whatever its last use says.
     *
     * @param  array<string, true>  $withLiveSecret  primary keys of the apps holding a live secret
     */
    public static function status(Client $client, array $withLiveSecret): string
    {
        return match (true) {
            AppTabs::holdsSharedSecret($client) && ! isset($withLiveSecret[$client->id]) => self::STATUS_DISABLED,
            $client->last_used_at === null => self::STATUS_NEVER_USED,
            $client->last_used_at->lt(now()->subDays(self::STALE_AFTER_DAYS)) => self::STATUS_STALE,
            default => self::STATUS_ACTIVE,
        };
    }

    /**
     * WHICH PRODUCT EACH APP BELONGS TO — the head app it nests under, or null for a
     * product of its own.
     *
     * A product is rarely one client. Billing is a web app and the machine client that
     * reads shared properties; Infra is a web app, an engine, an approvals agent and a
     * vault. Listed flat, the forty clients behind fifteen products read as forty things.
     *
     * Nothing names a product outright, so it is read from what an operator already wrote:
     *  - an app-catalog entry that lists several clients makes them one product, the client
     *    the entry is named after (else its first) at the head;
     *  - otherwise an app whose name extends another app's name by whole words belongs to
     *    the longest such app — "Cbox Billing shared properties" to "Cbox Billing".
     * An app only ever joins one with the same owner organization, and a chain collapses to
     * its head, so the list nests one level deep.
     *
     * @param  iterable<Client>  $clients
     * @return array<string, string|null> primary key => the head app's primary key, or null
     */
    public static function parents(iterable $clients): array
    {
        /** @var array<string, Client> $byId */
        $byId = [];
        /** @var array<string, Client> $byClientId */
        $byClientId = [];

        foreach ($clients as $client) {
            $byId[$client->id] = $client;
            $byClientId[$client->client_id] = $client;
        }

        /** @var array<string, string|null> $parent */
        $parent = [];

        foreach (CatalogEntry::query()->orderBy('position')->get() as $entry) {
            $members = [];

            foreach ($entry->client_ids as $clientId) {
                if (isset($byClientId[$clientId])) {
                    $members[] = $byClientId[$clientId];
                }
            }

            if (count($members) < 2) {
                continue;
            }

            $head = $members[0];

            foreach ($members as $member) {
                if (mb_strtolower($member->name) === mb_strtolower($entry->name)) {
                    $head = $member;
                }
            }

            foreach ($members as $member) {
                if ($member->id !== $head->id && ! isset($parent[$member->id]) && $member->organization_id === $head->organization_id) {
                    $parent[$member->id] = $head->id;
                }
            }
        }

        foreach ($byId as $client) {
            if (isset($parent[$client->id])) {
                continue;
            }

            $name = mb_strtolower(trim($client->name));
            $best = null;
            $bestLength = 0;

            foreach ($byId as $candidate) {
                if ($candidate->id === $client->id || $candidate->organization_id !== $client->organization_id) {
                    continue;
                }

                $prefix = mb_strtolower(trim($candidate->name));

                if ($prefix !== '' && str_starts_with($name, $prefix.' ') && mb_strlen($prefix) > $bestLength) {
                    $best = $candidate->id;
                    $bestLength = mb_strlen($prefix);
                }
            }

            $parent[$client->id] = $best;
        }

        // Collapse chains to their head, guarding against a cycle a catalog could draw.
        $heads = [];

        foreach (array_keys($byId) as $id) {
            $seen = [$id => true];
            $at = $parent[$id] ?? null;
            $head = null;

            while ($at !== null && ! isset($seen[$at])) {
                $seen[$at] = true;
                $head = $at;
                $at = $parent[$at] ?? null;
            }

            $heads[$id] = $head;
        }

        return $heads;
    }

    /**
     * The apps of one group, or all of them for none.
     *
     * @param  list<Client>  $clients
     * @return list<Client>
     */
    public static function inGroup(array $clients, ?string $group): array
    {
        return $group === null
            ? $clients
            : array_values(array_filter($clients, fn (Client $client): bool => self::groupOf($client) === $group));
    }

    /**
     * The apps of one kind, or all of them for none.
     *
     * @param  list<Client>  $clients
     * @return list<Client>
     */
    public static function ofKind(array $clients, ?string $kind): array
    {
        return $kind === null
            ? $clients
            : array_values(array_filter($clients, fn (Client $client): bool => self::kindOf($client) === $kind));
    }

    /** Whether the app is one a person signs in to through a browser. */
    public static function signsInThroughBrowser(Client $client): bool
    {
        return in_array('authorization_code', $client->grant_types ?? [], true);
    }

    /**
     * Where the app lives, as a person recognises it: the host of its first redirect URI.
     * Null for an app that is never returned to a browser.
     */
    public static function host(Client $client): ?string
    {
        foreach ($client->redirect_uris ?? [] as $uri) {
            $scheme = parse_url($uri, PHP_URL_SCHEME);

            // An app's own scheme (`dk.cbox.ai://auth/callback`) names the app, not a host.
            if (is_string($scheme) && ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                return $scheme.'://';
            }

            $host = parse_url($uri, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $port = parse_url($uri, PHP_URL_PORT);

                return is_int($port) ? $host.':'.$port : $host;
            }
        }

        return null;
    }

    /**
     * The catalog's face for each app a catalog entry names: its icon, dark icon and hue —
     * the same icon the person meets in the app switcher.
     *
     * @param  list<string>  $clientIds
     * @return array<string, array{iconUrl: string|null, iconDarkUrl: string|null, hue: string|null}>
     */
    public function faces(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $faces = [];

        foreach (CatalogEntry::query()->orderBy('position')->get() as $entry) {
            $presented = $this->catalog->present($entry);

            foreach ($entry->client_ids as $clientId) {
                if (! in_array($clientId, $clientIds, true) || isset($faces[$clientId])) {
                    continue;
                }

                $faces[$clientId] = [
                    'iconUrl' => is_string($presented['icon_url'] ?? null) ? $presented['icon_url'] : null,
                    'iconDarkUrl' => is_string($presented['icon_dark_url'] ?? null) ? $presented['icon_dark_url'] : null,
                    'hue' => $entry->hue,
                ];
            }
        }

        return $faces;
    }
}
