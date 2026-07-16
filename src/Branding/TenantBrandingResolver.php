<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Branding;

use Cbox\Console\Kit\Branding\Branding;
use Cbox\Console\Kit\Contracts\BrandingResolver;
use Cbox\Console\Kit\Contracts\CurrentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Cbox\Id\Whitelabel\Support\PaletteTokens;

/**
 * Resolves the branding for the current request from the tenant's {@see BrandProfile}.
 * It prefers the organization the console is scoped to (via the console's
 * {@see CurrentContext}), falling back to the environment-wide default. With no
 * environment in context, or no profile, it returns an EMPTY {@see Branding} so the
 * shell keeps its static CSS — the same inert behaviour as having no plugin at all.
 *
 * This overrides console-kit's null resolver, turning the whole white-label feature on.
 */
final class TenantBrandingResolver implements BrandingResolver
{
    public function __construct(
        private readonly BrandProfiles $profiles,
        private readonly EnvironmentContext $environment,
        private readonly CurrentContext $context,
    ) {}

    public function resolve(): Branding
    {
        // No environment resolved (e.g. the control plane) => no tenant branding.
        if (! $this->environment->has()) {
            return Branding::empty();
        }

        $profile = $this->currentProfile();

        if ($profile === null) {
            return Branding::empty();
        }

        return new Branding(
            tokens: PaletteTokens::normalize($profile->palette),
            logoUrl: $profile->logo_url,
            faviconUrl: $profile->favicon_url,
            appName: $profile->app_name,
        );
    }

    /** The org-specific profile if the console is scoped to one, else the env default. */
    private function currentProfile(): ?BrandProfile
    {
        $organizationId = $this->context->organizationId();

        if ($organizationId !== null) {
            $organizationProfile = $this->profiles->forOrganization($organizationId);

            if ($organizationProfile !== null) {
                return $organizationProfile;
            }
        }

        return $this->profiles->forEnvironment();
    }
}
