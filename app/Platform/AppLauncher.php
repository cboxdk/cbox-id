<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\AppCatalog\AppCatalog;
use Illuminate\Auth\Access\AuthorizationException;

/** Both dashboard and navigation read the explicitly published catalog. */
final readonly class AppLauncher
{
    public function __construct(private CurrentUser $me, private AppCatalog $catalog) {}

    /** @return list<array<string, mixed>> */
    public function apps(): array
    {
        return $this->catalog()['apps'];
    }

    /** @return array{apps: list<array<string, mixed>>, unavailable: list<array<string, mixed>>} */
    public function catalog(): array
    {
        if (! $this->me->check() || ! $this->catalog->hasPublishedApps()) {
            return ['apps' => [], 'unavailable' => []];
        }

        try {
            return $this->catalog->catalogFor($this->me->id(), $this->me->organizationId());
        } catch (AuthorizationException) {
            // A withdrawn membership must not prevent rendering account recovery or
            // organization switching. The explicit account API still refuses this context.
            return ['apps' => [], 'unavailable' => []];
        }
    }
}
