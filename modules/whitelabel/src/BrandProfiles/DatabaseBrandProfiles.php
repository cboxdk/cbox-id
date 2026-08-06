<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\BrandProfiles;

use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandProfile;

/**
 * The default {@see BrandProfiles}: Eloquent over `whitelabel_brand_profiles`. Reads
 * are constrained to the current environment by the model's global scope, so the
 * queries here need no explicit environment predicate.
 */
class DatabaseBrandProfiles implements BrandProfiles
{
    public function forEnvironment(): ?BrandProfile
    {
        return BrandProfile::query()->whereNull('organization_id')->first();
    }

    public function forOrganization(string $organizationId): ?BrandProfile
    {
        return BrandProfile::query()->where('organization_id', $organizationId)->first();
    }

    public function save(BrandProfile $profile): BrandProfile
    {
        $profile->save();

        return $profile;
    }
}
