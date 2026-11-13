<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\AppKind;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Builder;

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
 * apps registered by or for one organization. They are cut in SQL so the list still pages.
 *
 * THE KINDS are {@see AppKind}, read back from the grants exactly as
 * {@see AppKind::forClient()} does, so a filter can never disagree with the label on the
 * row it returns. Advanced — anything matching no preset — is "Other".
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
     * Narrow to one group.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function inGroup(Builder $query, string $group): Builder
    {
        return match ($group) {
            self::GROUP_FIRST_PARTY => $query->whereNull('organization_id')->where('first_party', true),
            self::GROUP_ENVIRONMENT => $query->whereNull('organization_id')->where('first_party', false),
            self::GROUP_ORGANIZATIONS => $query->whereNotNull('organization_id'),
            default => $query,
        };
    }

    /**
     * Group order, then name: the order the sections are drawn in, so a page of the list
     * reads as consecutive groups rather than one interleaved column.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function ordered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('organization_id is not null')
            ->orderByDesc('first_party')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * Narrow to one kind of app, by the same grants {@see AppKind::forClient()} reads.
     *
     * A preset is matched on its exact grants, which JSON containment can only approximate
     * from below; the extra grants a preset does not carry are excluded explicitly, so the
     * rows returned are the rows that would be labelled that kind.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function ofKind(Builder $query, string $kind): Builder
    {
        $preset = AppKind::tryFrom($kind === 'other' ? 'advanced' : $kind);

        if ($preset === null) {
            return $query;
        }

        if ($preset === AppKind::Advanced) {
            return $query->where(function (Builder $q): void {
                foreach ([AppKind::WebApp, AppKind::SpaOrMobile, AppKind::CliOrDevice, AppKind::Service, AppKind::Agent] as $kind) {
                    $q->whereNot(fn (Builder $q): Builder => self::exactly($q, $kind));
                }
            });
        }

        return self::exactly($query, $preset);
    }

    /**
     * The grants and client type of exactly one preset.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private static function exactly(Builder $query, AppKind $kind): Builder
    {
        $grants = $kind->grantTypes();

        $query->where('type', $kind->clientType()->value);

        foreach ($grants as $grant) {
            $query->whereJsonContains('grant_types', $grant);
        }

        foreach (self::knownGrants() as $grant) {
            if (! in_array($grant, $grants, true)) {
                $query->whereJsonDoesntContain('grant_types', $grant);
            }
        }

        return $query->whereJsonLength('grant_types', count($grants));
    }

    /** @return list<string> every grant a preset is made of */
    private static function knownGrants(): array
    {
        $grants = [];

        foreach ([AppKind::WebApp, AppKind::SpaOrMobile, AppKind::CliOrDevice, AppKind::Service, AppKind::Agent] as $kind) {
            $grants = [...$grants, ...$kind->grantTypes()];
        }

        return array_values(array_unique($grants));
    }

    /** The kind filter's value for an app: its preset, or `other`. */
    public static function kindOf(Client $client): string
    {
        $kind = AppKind::forClient($client);

        return $kind === AppKind::Advanced ? 'other' : $kind->value;
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
