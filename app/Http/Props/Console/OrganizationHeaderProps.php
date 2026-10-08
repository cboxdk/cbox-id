<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Http\Props\Shared\LinkTabProps;

/**
 * What every tab of an organization's page draws above its own content: the way back to the
 * list, the organization's name, its copyable id and its status, the tabs, and the one
 * action an environment administrator most often came here for — an Admin Portal link for
 * the customer's IT administrator.
 *
 * `portalLink` carries what that link's dialog asks: which intents it covers (each saying
 * whether the organization's plan includes it), how long it may wait to be opened, and the
 * languages the link can be mailed in.
 */
final readonly class OrganizationHeaderProps implements Prop
{
    /**
     * @param  list<LinkTabProps>  $tabs
     * @param  list<PortalIntentProps>  $portalIntents  what a portal link may cover, for this organization
     * @param  list<OptionProps>  $portalLifetimes  how long it may wait, in minutes
     * @param  list<OptionProps>  $portalLocales  the languages it may be mailed in
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $status,
        public array $tabs,
        public string $indexHref,
        public string $portalLinkHref,
        public array $portalIntents,
        public array $portalLifetimes,
        public array $portalLocales,
        public string $portalDefaultLocale,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'tabs' => array_map(static fn (LinkTabProps $tab): array => $tab->toArray(), $this->tabs),
            'indexHref' => $this->indexHref,
            'portalLink' => [
                'href' => $this->portalLinkHref,
                'intents' => array_map(static fn (PortalIntentProps $intent): array => $intent->toArray(), $this->portalIntents),
                'lifetimes' => array_map(static fn (OptionProps $option): array => $option->toArray(), $this->portalLifetimes),
                'locales' => array_map(static fn (OptionProps $option): array => $option->toArray(), $this->portalLocales),
                'defaultLocale' => $this->portalDefaultLocale,
            ],
        ];
    }
}
