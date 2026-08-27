<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;

/**
 * One entry in the console's second tier.
 *
 * `badge` is the soft entitlement lock — the page is SHOWN but marked, because hiding a
 * capability an organization could buy leaves them unable to discover it exists. A hard
 * gate (the console-kit feature) removes the page from this list entirely and 404s the
 * route, which is a different question with a different answer.
 */
final readonly class NavPageProps implements Prop
{
    public function __construct(
        public string $route,
        public string $href,
        public string $label,
        public bool $active,
        public ?string $badge = null,
    ) {}

    /**
     * @return array{route: string, href: string, label: string, active: bool, badge: string|null}
     */
    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'href' => $this->href,
            'label' => $this->label,
            'active' => $this->active,
            'badge' => $this->badge,
        ];
    }
}
