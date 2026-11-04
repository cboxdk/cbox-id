<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\AppCatalog\AppCatalog;

/** Both dashboard and navigation read the explicitly published catalog. */
final readonly class AppLauncher
{
    public function __construct(private CurrentUser $me, private AppCatalog $catalog) {}

    /** @return list<array<string, mixed>> */
    public function apps(): array
    {
        return $this->me->check() ? $this->catalog->forUser($this->me->id(), $this->me->organizationId()) : [];
    }
}
