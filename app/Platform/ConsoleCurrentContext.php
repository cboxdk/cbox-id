<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Console\Kit\Contracts\CurrentContext;

/**
 * Binds the console-kit {@see CurrentContext} to this app's {@see CurrentUser}, so a
 * plugin (billing, …) can resolve the current org / user / admin without depending on
 * the app's own auth internals.
 */
final class ConsoleCurrentContext implements CurrentContext
{
    public function __construct(private readonly CurrentUser $me) {}

    public function organizationId(): ?string
    {
        return $this->me->check() ? $this->me->organizationId() : null;
    }

    public function userId(): ?string
    {
        return $this->me->check() ? $this->me->id() : null;
    }

    public function isAdmin(): bool
    {
        return $this->me->check() && $this->me->isAdmin();
    }
}
