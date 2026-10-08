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
 * `portalLink` is null when the organization is entitled to neither single sign-on nor
 * directory sync: a link that opens a setup screen the plan does not include is a dead end
 * handed to somebody outside.
 */
final readonly class OrganizationHeaderProps implements Prop
{
    /**
     * @param  list<LinkTabProps>  $tabs
     * @param  list<OptionProps>  $portalCovers  what a portal link may cover, for this organization
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $status,
        public array $tabs,
        public string $indexHref,
        public ?string $portalLinkHref,
        public array $portalCovers,
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
            'portalLink' => $this->portalLinkHref === null ? null : [
                'href' => $this->portalLinkHref,
                'covers' => array_map(static fn (OptionProps $option): array => $option->toArray(), $this->portalCovers),
            ],
        ];
    }
}
