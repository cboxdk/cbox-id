<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;
use App\Platform\Console\ConsoleSynonyms;

/**
 * One entry in the console's second tier.
 *
 * `badge` is the soft entitlement lock — the page is SHOWN but marked, because hiding a
 * capability an organization could buy leaves them unable to discover it exists. A hard
 * gate (the console-kit feature) removes the page from this list entirely and 404s the
 * route, which is a different question with a different answer.
 *
 * `count` is something on that page waiting for the person reading the rail — the
 * approvals an agent is holding for THEM — drawn as a number beside the label, and as a
 * dot on the area while the rail is collapsed. Null when there is nothing to say.
 *
 * `keywords` are the other words for this page that ⌘K matches but never shows — "SAML"
 * and "OIDC" for Enterprise SSO, "tenant" for Organizations ({@see ConsoleSynonyms}).
 */
final readonly class NavPageProps implements Prop
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $route,
        public string $href,
        public string $label,
        public bool $active,
        public ?string $badge = null,
        public ?int $count = null,
        public array $keywords = [],
    ) {}

    /**
     * @return array{route: string, href: string, label: string, active: bool, badge: string|null, count: int|null, keywords: list<string>}
     */
    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'href' => $this->href,
            'label' => $this->label,
            'active' => $this->active,
            'badge' => $this->badge,
            'count' => $this->count,
            'keywords' => $this->keywords,
        ];
    }
}
