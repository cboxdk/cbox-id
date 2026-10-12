<?php

declare(strict_types=1);

namespace App\Mail;

use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\BrandContext;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\Color;
use App\Platform\Appearance\ThemePresets;
use App\Platform\MailLinks;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Throwable;

/**
 * WHICH BRAND A MAIL IS SENT IN — the same answer the page the person was on gave.
 *
 * Every mail a person gets from Cbox ID is somebody's: the deployment's, an environment's
 * (a vendor whose end users never heard of us), or one organization's inside it. The mail
 * used to be the deployment's, always — the configured product name, an indigo button and
 * the deployment's sender — while the sign-in page the same person had just left was
 * painted in the vendor's colours, logo and name. A code that arrives under a stranger's
 * name, from a stranger, is a code a careful person does not type in.
 *
 * Resolved here, per part, most specific first:
 *
 *  - NAME — the white-label profile's product name (the organization's, then the
 *    environment's: "the product name shown in place of Cbox ID"); else, on a customer's
 *    environment, the environment's own name, which is what its sign-in pages are titled
 *    ({@see BrandContext::name()}); else the deployment's `CBOX_ID_BRAND_NAME`.
 *    Never an organization's NAME: "Set up Acme on Acme" is what that would write.
 *  - LOGO — the organization's uploaded logo, then the environment's; always an image
 *    this application serves, at an origin {@see MailLinks} vouches for. Never the remote
 *    `brand_logo_url` of old: in a mail that is a read receipt.
 *  - COLOURS — the sign-in theme's light primary (the organization's theme when it has
 *    one, else the environment's), else the platform preset's. The button text and the
 *    accent-as-text are derived from it to stay readable ({@see Color}), so a tenant with
 *    a pale yellow brand gets dark text on its button rather than an unreadable one.
 *  - SENDER — the white-label profile's email sender name; else, when the mail is branded
 *    at all, the brand's name, so "Northwind" mail does not arrive from "Cbox ID"; else
 *    null, which leaves the deployment's `MAIL_FROM_NAME`. Only the NAME: the address is
 *    the deployment's own, the one SPF, DKIM and DMARC are set up for.
 *
 * THE ORGANIZATION is the one the flow knows — an invitation to it, its Admin Portal link,
 * its certificate warning — or the one the sign-in door pinned ({@see BrandContext}), as
 * on `/o/{slug}/login`. Without either, the environment's brand is the answer.
 *
 * NOTHING HERE MAY STOP A MAIL. A sign-in code that cannot be sent because a brand
 * profile table is missing is a locked-out user; every lookup fails soft to the next.
 */
final readonly class MailBrands
{
    public function __construct(
        private EnvironmentContext $environments,
        private PlaneResolver $planes,
        private MailLinks $links,
    ) {}

    /**
     * The brand for a mail sent now — for `$organizationId` when the flow knows it, else
     * for the organization this request's door is branded for, else the environment.
     */
    public function current(?string $organizationId = null): MailBrand
    {
        $environment = $this->environment();
        $organization = $this->organization($organizationId);

        $profile = $this->profile($organization?->id);
        $environmentProfile = $this->profile(null);

        $branded = $this->text($profile?->app_name)
            ?? $this->text($environmentProfile?->app_name)
            ?? ($environment !== null && $this->onCustomerEnvironment() ? $this->text($environment->name) : null);

        $name = $branded ?? $this->deploymentName();

        $accent = $this->accent($organization, $environment);

        return new MailBrand(
            name: $name,
            logo: $this->logo($organization?->id, $environment !== null),
            accent: $accent,
            onAccent: Color::readableForeground($accent),
            accentText: Color::readableOn($accent, '#ffffff'),
            senderName: $this->text($profile?->email_from_name)
                ?? $this->text($environmentProfile?->email_from_name)
                ?? $branded,
        );
    }

    /** The deployment's own product name — `CBOX_ID_BRAND_NAME`. */
    public function deploymentName(): string
    {
        $brand = config('cbox-id.branding.name', 'Cbox ID');

        return is_string($brand) && trim($brand) !== '' ? trim($brand) : 'Cbox ID';
    }

    private function environment(): ?Environment
    {
        try {
            $key = $this->environments->current()?->environmentKey();

            return $key !== null ? Environment::query()->find($key) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function organization(?string $organizationId): ?Organization
    {
        try {
            if ($organizationId !== null && $organizationId !== '') {
                return Organization::query()->find($organizationId);
            }

            return app(BrandContext::class)->organization();
        } catch (Throwable) {
            return null;
        }
    }

    private function onCustomerEnvironment(): bool
    {
        try {
            return $this->planes->onCustomerEnvironment();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The white-label profile at one altitude — an organization's (`$organizationId`), or
     * the environment default (null). Null without the module, without an environment in
     * context (the profiles are environment-scoped rows), or on any failure.
     */
    private function profile(?string $organizationId): ?BrandProfile
    {
        if (! app()->bound(BrandProfiles::class) || ! $this->environments->has()) {
            return null;
        }

        try {
            $profiles = app(BrandProfiles::class);

            return $organizationId === null ? $profiles->forEnvironment() : $profiles->forOrganization($organizationId);
        } catch (Throwable) {
            return null;
        }
    }

    /** The sign-in theme's light-mode primary at the most specific altitude, else the platform's. */
    private function accent(?Organization $organization, ?Environment $environment): string
    {
        $appearance = Appearance::effective(
            $this->settings($organization?->settings),
            $this->settings($environment?->settings),
        );

        return $appearance?->light->primary
            ?? ThemePresets::get(ThemePresets::default())->light->primary;
    }

    /**
     * The organization's uploaded logo, else the environment's, as an absolute URL on an
     * origin this deployment states about itself — or null, for the monogram.
     */
    private function logo(?string $organizationId, bool $hasEnvironment): ?string
    {
        if (! $hasEnvironment) {
            return null;
        }

        try {
            $images = app(BrandImages::class);
            $url = ($organizationId !== null ? $images->url(BrandImage::Logo, $organizationId) : null)
                ?? $images->url(BrandImage::Logo, null);

            if ($url === null) {
                return null;
            }

            // A path is served by this application: made absolute on the canonical origin,
            // never the Host header. Anything else is the deployment's own CDN, as stored.
            return str_starts_with($url, '/') ? $this->links->to($url) : $url;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settings(mixed $settings): ?array
    {
        if (! is_array($settings)) {
            return null;
        }

        $narrowed = [];

        foreach ($settings as $key => $value) {
            if (is_string($key)) {
                $narrowed[$key] = $value;
            }
        }

        return $narrowed;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
