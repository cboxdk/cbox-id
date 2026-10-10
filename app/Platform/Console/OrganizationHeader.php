<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\BindConsoleOrganization;
use App\Http\Props\Console\OptionProps;
use App\Http\Props\Console\OrganizationHeaderProps;
use App\Http\Props\Console\PortalIntentProps;
use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Locale\HostedLocales;
use Cbox\Id\Organization\Models\Organization;

/**
 * The header every tab of an organization's page shares — built in one place, so thirteen
 * tabs cannot disagree about its name, its tabs, or what a portal link can cover.
 *
 * Shared with every page under `/admin/organizations/{organization}/…` by
 * {@see BindConsoleOrganization}, which is what lets a page written for
 * the environment-wide console (Enterprise SSO, Roles, the audit log) be drawn as a tab of
 * one organization without knowing it is one.
 */
final readonly class OrganizationHeader
{
    /** How long the dialog offers to let a link wait, in minutes, with what it calls each. */
    private const array LIFETIMES = [
        30 => '30 minutes',
        240 => '4 hours',
        1440 => '24 hours',
        4320 => '3 days',
        10080 => '7 days',
    ];

    public function __construct(
        private OrganizationTabs $tabs,
        private AdminPortal $portal,
        private HostedLocales $locales,
    ) {}

    public function for(string $organizationId, string $tab): ?OrganizationHeaderProps
    {
        $organization = Organization::query()->whereKey($organizationId)->first(['id', 'name', 'slug', 'status']);

        if ($organization === null) {
            return null;
        }

        return new OrganizationHeaderProps(
            id: $organization->id,
            name: $organization->name,
            slug: (string) $organization->slug,
            status: $organization->status->value,
            tabs: $this->tabs->for($organization->id, $tab),
            subTabs: $this->tabs->subTabsFor($organization->id, $tab),
            indexHref: route('environment.organizations'),
            portalLinkHref: route('environment.organizations.portal-links.store', ['organization' => $organization->id]),
            portalIntents: $this->portalIntents($organization->id),
            portalLifetimes: array_map(
                static fn (int $minutes, string $label): OptionProps => new OptionProps((string) $minutes, $label),
                array_keys(self::LIFETIMES),
                array_values(self::LIFETIMES),
            ),
            portalLocales: array_map(
                static fn ($locale): OptionProps => new OptionProps($locale->value, $locale->nativeName()),
                $this->locales->enabled(),
            ),
            portalDefaultLocale: $this->locales->default()->value,
        );
    }

    /**
     * Every intent, each saying whether this organization may have it — one its plan does not
     * include is shown and cannot be ticked, rather than offered and then refused by the
     * action: a choice the console offers only to be refused is the console lying about what
     * it can do, and one it hides is a feature nobody learns exists.
     *
     * @return list<PortalIntentProps>
     */
    private function portalIntents(string $organizationId): array
    {
        return array_map(fn (PortalIntent $intent): PortalIntentProps => new PortalIntentProps(
            value: $intent->value,
            label: $intent->label(),
            description: $intent->description(),
            available: $this->portal->intentUsable($organizationId, $intent),
        ), PortalIntent::cases());
    }
}
