<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Contracts;

use Cbox\Id\Whitelabel\Models\BrandProfile;

/**
 * The store of {@see BrandProfile}s. Every lookup is already environment-scoped by the
 * model's BelongsToEnvironment trait, so implementations never see cross-environment
 * rows. Bound via `bindIf` so a host can swap the store (e.g. a cache-backed reader).
 */
interface BrandProfiles
{
    /** The environment-wide default profile (organization_id is null), or null. */
    public function forEnvironment(): ?BrandProfile;

    /** The profile overriding branding for a single organization, or null. */
    public function forOrganization(string $organizationId): ?BrandProfile;

    /** Persist a profile (create or update) and return it. */
    public function save(BrandProfile $profile): BrandProfile;
}
