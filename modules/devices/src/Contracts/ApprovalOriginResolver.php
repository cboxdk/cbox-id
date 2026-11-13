<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Contracts;

use Cbox\Id\Devices\Enums\ApprovalKind;
use Cbox\Id\Devices\Support\NullApprovalOriginResolver;
use Cbox\Id\Devices\ValueObjects\ApprovalOrigin;

/**
 * What the host knows about the request filing an approval, and the language its approver
 * reads.
 *
 * The module's default ({@see NullApprovalOriginResolver}) knows
 * nothing and speaks the application's locale; the host binds its own — location from its
 * edge's headers, the device from the user agent, the app being signed in to,
 * the person's language preference.
 *
 * IMPLEMENTATIONS MUST FAIL OPEN: an unknown answers null, never an exception. Both are
 * asked inside the request that files an approval, and a lookup must never be what turns a
 * filed request into a failed one.
 */
interface ApprovalOriginResolver
{
    /**
     * The origin of the request being handled now — for a browser kind, the request a
     * person's browser made: city, region and country from the edge, the user agent, the
     * host. Any other kind was filed by a server and has no "where". Never the IP address.
     */
    public function capture(ApprovalKind $kind): ApprovalOrigin;

    /**
     * The browser and platform a person would recognise in a user agent, each null when
     * not recognised. Never the agent string itself.
     *
     * @return array{browser: string|null, platform: string|null}
     */
    public function device(?string $userAgent): array;

    /** The locale a push to $subjectId is written in. */
    public function localeFor(string $subjectId, ApprovalKind $kind): string;
}
