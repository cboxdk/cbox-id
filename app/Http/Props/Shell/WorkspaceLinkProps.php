<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;

/**
 * THE WAY BACK — the workspace an environment console belongs to, and where it lives.
 *
 * The environment console is reached through Projects › Open on the workspace's host, and
 * it had no way back: the rail was the environment's, the brand mark went to the
 * environment's overview, and the only exit was the browser's back button or a typed URL.
 * The topbar names the workspace and links to its Projects page, on every page.
 */
final readonly class WorkspaceLinkProps implements Prop
{
    public function __construct(
        public string $name,
        /** Absolute: the workspace console is on another host. */
        public string $href,
    ) {}

    /**
     * @return array{name: string, href: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'href' => $this->href,
        ];
    }
}
