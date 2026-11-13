<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Devices\Contracts\ApprovalOriginResolver;
use Cbox\Id\Devices\Enums\ApprovalKind;
use Cbox\Id\Devices\ValueObjects\ApprovalOrigin;

/** Knows nothing about where a request came from; speaks the application's locale. */
final readonly class NullApprovalOriginResolver implements ApprovalOriginResolver
{
    public function capture(ApprovalKind $kind): ApprovalOrigin
    {
        return new ApprovalOrigin($kind);
    }

    public function device(?string $userAgent): array
    {
        return ['browser' => null, 'platform' => null];
    }

    public function localeFor(string $subjectId, ApprovalKind $kind): string
    {
        return app()->getLocale();
    }
}
