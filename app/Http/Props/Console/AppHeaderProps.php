<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Http\Props\Shared\LinkTabProps;

/**
 * What every tab of an app's page draws above its own content: the app's name and kind,
 * the tabs, and the two ways to take the app somewhere else.
 *
 * `blueprintHref` is null for somebody who may only look at the app — a blueprint is the
 * app's whole configuration, and this console shows that only to whoever manages it.
 * `copy` is null outside the environment console: an organization has no other
 * environment to copy its app into.
 */
final readonly class AppHeaderProps implements Prop
{
    /**
     * @param  list<LinkTabProps>  $tabs
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $clientId,
        public bool $confidential,
        public bool $firstParty,
        public string $kindLabel,
        public array $tabs,
        public string $indexHref,
        public ?string $blueprintHref,
        public ?AppCopyProps $copy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'clientId' => $this->clientId,
            'confidential' => $this->confidential,
            'firstParty' => $this->firstParty,
            'kindLabel' => $this->kindLabel,
            'tabs' => array_map(static fn (LinkTabProps $tab): array => $tab->toArray(), $this->tabs),
            'indexHref' => $this->indexHref,
            'blueprintHref' => $this->blueprintHref,
            'copy' => $this->copy?->toArray(),
        ];
    }
}
