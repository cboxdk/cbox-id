<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

use App\Platform\Invitations\AppReturnTargets;

/**
 * The app an invitation came from, and where in it to send the person once they accept.
 *
 * Only ever built by {@see AppReturnTargets}, which is what makes
 * `$url` safe to redirect to: it has been checked against the app's registered redirect
 * origins, and it is checked again at acceptance rather than trusted from storage.
 *
 * `$url` is null when an invitation names its app without a return address — the invitee
 * is told which app they are joining, and lands in this product's console afterwards.
 */
final readonly class ReturnTarget
{
    public function __construct(
        public string $clientId,
        public string $appName,
        public ?string $url = null,
    ) {}
}
