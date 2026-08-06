<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Organization\Enums\OrganizationStatus;

/**
 * The single answer to "may anything still be done THROUGH this organization?".
 *
 * The enforcement points used to each test `=== OrganizationStatus::Suspended` by
 * hand. `Deleted` — which the environment console writes from its "Delete
 * organization" button — matched none of them, so a deleted tenant's members kept
 * signing in, kept consenting, and kept minting tokens; every other `Deleted`
 * reference in the app was cosmetic (list filtering, badge colours). A status the UI
 * writes and the security layer ignores is not a status.
 *
 * `Deleted` is enforced rather than removed. The case lives in the `cboxdk/laravel-id`
 * package, so this app cannot drop it; it also carries real meaning — the soft-hide
 * that keeps a tenant's rows for audit and recovery while taking it out of every list.
 * A soft-deleted tenant must be at least as restrictive as a suspended one, so it is.
 *
 * The `match` is deliberately exhaustive with no `default`: a status added to the enum
 * upstream fails static analysis here rather than defaulting to "allowed", which is the
 * exact way `Deleted` slipped through in the first place.
 */
final class OrganizationAccess
{
    /**
     * The past-tense phrase naming why the organization is refused ("suspended",
     * "deleted"), or null when it may still be acted through.
     *
     * Callers branch on null and interpolate the phrase into their own message, so
     * one status decision serves every enforcement point without flattening their
     * distinct copy into a shared string.
     */
    public static function refusalPhrase(?OrganizationStatus $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match ($status) {
            OrganizationStatus::Active => null,
            OrganizationStatus::Suspended => 'suspended',
            OrganizationStatus::Deleted => 'deleted',
        };
    }

    /** Whether the organization refuses every authenticated action through it. */
    public static function isRevoked(?OrganizationStatus $status): bool
    {
        return self::refusalPhrase($status) !== null;
    }
}
