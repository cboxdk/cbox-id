<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use App\Http\Props\Shared\BrandProps;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\HtmlString;

/**
 * WHICH BRAND THIS REQUEST IS PAINTED IN — resolved once, read twice.
 *
 * The sign-in surfaces are branded per organization (`/o/{slug}/login`) over an
 * environment default, and both halves of the answer are needed in two different
 * places on the same request:
 *
 *  - the ROOT VIEW, which must emit the token override as a `<style>` block in `<head>`
 *    so the very first paint is already the customer's colour. It cannot wait for React:
 *    the bundle is deferred by definition, and a branded login that flashes Cbox blue
 *    before turning green is worse than one that was never branded at all;
 *  - the PAGE PROPS, so React can render the customer's name and logo.
 *
 * Under Volt this was `View::share('cboxBrand', …)` from a component's `mount()`, read by
 * a layout that re-resolved the environment half itself. Two readers, one of them
 * untyped, and the environment lookup ran on every page that had a layout whether or not
 * anything branded it.
 *
 * A controller pins the organization ({@see self::brand()}); everything else is derived.
 * Nothing pins it on the console planes, and that is the correct answer there — the
 * console is Cbox's own surface, not the customer's.
 */
final class BrandContext
{
    private ?Organization $organization = null;

    private bool $environmentResolved = false;

    private bool $door = false;

    private ?Environment $environment = null;

    /**
     * Paint this request in one organization's brand.
     *
     * Called by the controllers that serve a branded door — the slug login, the admin
     * portal, the OAuth consent screen — and by nobody else.
     */
    public function brand(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    /**
     * This request renders a sign-in DOOR — sign-in, sign-up, the organization picker,
     * an invitation, a magic link, a password reset.
     *
     * On a customer's environment a door with no organization pinned is painted in the
     * ENVIRONMENT's brand: its name and logo, beside the colours the root view already
     * emits. Without this the vendor's end users met Cbox's own name and marketing on
     * the vendor's sign-up page. Only doors: the consoles on that host are Cbox's surface,
     * and their tab titles stay ours.
     */
    public function atTheDoor(): void
    {
        $this->door = true;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    /**
     * The organization's settings over the environment's, or null when neither has been
     * customized — in which case the platform default in `app.css` stands untouched, and
     * we deliberately emit no override rather than re-stating the defaults.
     */
    public function appearance(): ?Appearance
    {
        return Appearance::effective(
            $this->settingsOf($this->organization?->settings),
            $this->settingsOf($this->environment()?->settings),
        );
    }

    /**
     * The `<style>` block for `<head>`, or null. Returned as an HtmlString because it is
     * generated CSS, not user input: {@see AppearanceCss} builds it from typed colour
     * value objects, so there is no path from a settings bag to raw markup here.
     */
    public function css(): ?HtmlString
    {
        $appearance = $this->appearance();

        return $appearance !== null ? AppearanceCss::render($appearance) : null;
    }

    /**
     * The name to put in `<title>` and on the sign-in card, or null for the platform's own:
     * the pinned organization's, else — at a door on a customer's environment — the
     * environment's, which is the name its Appearance page previews the sign-in under.
     */
    public function name(): ?string
    {
        $name = $this->organization?->name;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        if (! $this->door || ! app(PlaneResolver::class)->onCustomerEnvironment()) {
            return null;
        }

        $name = $this->environment()?->name;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The organization's uploaded logo, else the environment's, else none — always an
     * image this application serves ({@see BrandImages}).
     *
     * NOT `brand_logo_url`. That was an https URL typed on the Appearance page and drawn
     * as-is, so every visitor of a branded sign-in page fetched an image from whoever hosted
     * it — their address, browser and arrival time handed to a third party on the one page
     * where it matters most. Stored values are left in settings (nothing fetches them, ever)
     * and are no longer drawn; the console asks the administrator to upload instead.
     */
    public function logo(): ?string
    {
        return $this->image(BrandImage::Logo);
    }

    /**
     * The uploaded favicon for this door, or null for the platform's own. Only on a page
     * that is branded at all ({@see self::name()}): the console's tab keeps Cbox's icon.
     */
    public function favicon(): ?string
    {
        return $this->name() === null ? null : $this->image(BrandImage::Favicon);
    }

    /**
     * The brand as page props. Null when nothing is branded, so a React page can ask one
     * question (`brand === null`) rather than three.
     */
    public function toProps(): ?BrandProps
    {
        $name = $this->name();

        if ($name === null) {
            return null;
        }

        return new BrandProps(name: $name, logo: $this->logo());
    }

    /**
     * The host-pinned environment, resolved at most once per request — and only when
     * something actually asks for the brand. A console page never does.
     */
    private function environment(): ?Environment
    {
        if ($this->environmentResolved) {
            return $this->environment;
        }

        $this->environmentResolved = true;

        $key = app(EnvironmentContext::class)->current()?->environmentKey();

        $this->environment = $key !== null ? Environment::query()->find($key) : null;

        return $this->environment;
    }

    /**
     * The `settings` JSON column, narrowed. The model can only promise `array`, and
     * {@see Appearance::effective()} wants a string-keyed bag — so the keys are checked
     * here rather than asserted, because a settings blob written by an older release is
     * exactly the kind of thing that turns out not to have the shape it promised.
     *
     * @return array<string, mixed>|null
     */
    private function settingsOf(mixed $settings): ?array
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

    private function image(BrandImage $kind): ?string
    {
        $images = app(BrandImages::class);
        $organizationId = $this->organization?->id;

        $own = is_string($organizationId) ? $images->url($kind, $organizationId) : null;

        // The environment's only where there IS one: a request with no environment pinned
        // (the console's own root) has no environment default to fall back on.
        return $own ?? ($this->environment() !== null ? $images->url($kind, null) : null);
    }
}
