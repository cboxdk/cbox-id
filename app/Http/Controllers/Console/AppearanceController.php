<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Branding\AppearanceFields;
use App\Actions\Branding\SetAppearance;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\SaveAppearanceRequest;
use App\Platform\Actions\ActionRefused;
use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\ThemeFont;
use App\Platform\Appearance\ThemePresets;
use App\Platform\Appearance\ThemeRadius;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * CONSOLE › APPEARANCE — the hosted-sign-in theme: presets, colours, corners and type,
 * edited against a live preview. One page, both planes.
 *
 * The two pages looked like the same feature and were not quite: the organization one
 * themed an ORGANIZATION (which overrides the environment default), the environment one
 * themed the ENVIRONMENT's default (which every organization in it inherits). That
 * difference is a real capability, not a plane detail — so it survives as an explicit
 * choice rather than being implied by which console you happened to open, and it is
 * offered and enforced on the environment plane alone. An organization administrator who
 * could reach it would be re-theming the sign-in page of every other tenant here.
 *
 * The organization page also refused an unreadable palette and the environment page did
 * not, so an operator could set an environment default that no tenant's users could read.
 * That gate is on both now — in the ACTION (`branding.appearance.set`) the management API
 * runs too, so the rule is the same whichever door changes a theme.
 */
final readonly class AppearanceController extends ConsoleController
{
    public function edit(EnvironmentContext $environments): Response
    {
        $this->scope->assertMayAdminister();

        $mayThemeEnvironment = $this->scope->plane() === ConsolePlane::Environment;

        /*
         * WHICH THING IS BEING THEMED is the page's ADDRESS, not a choice on it. The
         * environment console's Appearance page is the environment default every
         * organization inherits; one organization's own theme is its Branding tab
         * (`/admin/organizations/{organization}/branding`). It used to be a toggle on one
         * page, aimed by whichever organization the console header had been pointed at —
         * an operator could re-theme one tenant believing they were setting the default
         * for all of them.
         */
        $environmentDefault = $mayThemeEnvironment && $this->scope->organizationId() === null;

        $organization = $this->organization();
        $environment = $this->environment($environments);
        $target = $environmentDefault ? $environment : $organization;

        return $this->page('console/appearance', 'Appearance', [
            'help' => HelpProps::for(HelpTopic::Appearance),
            /*
             * `appearance`, NOT `theme`. The shell shares a prop called `theme` — the
             * CONSOLE's own light/dark preference, which the theme toggle reads — and a
             * page prop of the same name replaces it. This page is about a different
             * theme entirely: the one a customer ships to their own users.
             * {@see PageController::page()} refuses the collision outright.
             */
            'appearance' => $this->seed($target),
            // The catalogue, converted once at this serialization boundary: the editor
            // works in plain JSON and the domain model is typed.
            'presets' => ThemePresets::toPayload(),
            'fonts' => ThemeFont::stacks(),
            'fontLabels' => ThemeFont::labels(),
            'radii' => ThemeRadius::values(),
            // THE VIEW HALF asks the SCOPE rather than assuming the plane it was written
            // for, so the control the page draws and the write the server accepts come
            // from one rule.
            'mayThemeEnvironment' => $mayThemeEnvironment,
            'environmentDefault' => $environmentDefault,
            // Nothing to theme: a member who belongs to no organization, or an environment
            // that could not be resolved.
            'hasTarget' => $target !== null,
            /*
             * A remote logo URL saved before logos became uploads. It is no longer drawn on
             * any hosted page — it was a beacon to whoever hosted it — so until a logo is
             * uploaded (or removed) here the editor says so, rather than letting the
             * administrator discover a missing logo on their own sign-in page.
             */
            'remoteLogoIgnored' => $target !== null && AppearanceFields::remoteLogoIgnored($target->settings),
            'imagesAccepted' => app(BrandImages::class)->accepting(),
            // WHERE SAVE POSTS, resolved by the server: one controller action serves three
            // route names — both consoles' pages and an organization's Branding tab.
            'saveHref' => $mayThemeEnvironment && ! $environmentDefault
                ? route('environment.organizations.branding.update')
                : $this->url('appearance.update'),
        ]);
    }

    /**
     * Save the theme through the ACTION the management API runs ({@see SetAppearance}),
     * which refuses an unreadable palette — on both altitudes, so an operator cannot set an
     * environment default that no tenant's users can read.
     */
    public function update(SaveAppearanceRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * WHOSE THEME, from the address alone: the environment default on the environment
         * console's own Appearance page, the organization on its Branding tab and on the
         * organization console. The form's `environmentDefault` is not trusted to choose —
         * its arrival on the organization plane is a forged payload, refused rather than
         * quietly downgraded, because treating a forgery as a typo is how a control stops
         * being one.
         */
        $environmentDefault = $this->scope->plane() === ConsolePlane::Environment && $this->scope->organizationId() === null;

        if ($request->environmentDefault()) {
            abort_unless($environmentDefault, 403,
                'Only an environment administrator may change the environment default theme, on the environment\'s own Appearance page.');
        }

        /*
         * `attempt()`, not `act()`: a refusal goes back WITHOUT the input. `act()` flashes
         * the whole request into the session, and here that includes up to a megabyte of
         * base64 image per refused save.
         */
        $result = $this->attempt(SetAppearance::class, [
            // `requireOrganizationId()`, not the nullable reader: with none resolved this
            // write would otherwise land wherever a downstream default pointed.
            'organization_id' => $environmentDefault ? null : $this->scope->requireOrganizationId(),
            // Through the sanitizer first, so the editor's extra keys (its name and image
            // previews) never reach the action as theme fields.
            'theme' => Appearance::fromArray($request->theme())->toArray(),
            ...$request->images(),
        ], ['theme' => 'theme', 'logo' => 'logo', 'favicon' => 'favicon'], 'theme');

        if ($result instanceof ActionRefused) {
            $field = in_array($result->field, ['logo', 'favicon'], true) ? (string) $result->field : 'theme';

            return back()->withErrors([$field => $result->getMessage()]);
        }

        return back()->with('status', $environmentDefault ? 'Environment appearance saved.' : 'Appearance saved.');
    }

    /**
     * The editor's starting state — whichever thing is currently being themed.
     *
     * @return array<string, mixed>
     */
    private function seed(Environment|Organization|null $target): array
    {
        $settings = $target === null ? [] : $target->settings;

        $organizationId = $target instanceof Organization ? $target->id : null;
        $images = app(BrandImages::class);

        return [
            ...Appearance::fromSettings($settings)->toArray(),
            // The UPLOADED images at this altitude, as this application serves them. The
            // legacy remote URL is deliberately not seeded: the editor never draws it.
            'logo' => $target === null ? '' : ($images->url(BrandImage::Logo, $organizationId) ?? ''),
            'favicon' => $target === null ? '' : ($images->url(BrandImage::Favicon, $organizationId) ?? ''),
            'name' => $target === null ? '' : $target->name,
        ];
    }

    /**
     * The organization being themed — the SCOPE's, never a form field's.
     *
     * On the organization plane it is the member's own and nothing in the request can
     * change it; on the environment plane it is the one the URL names, checked against this
     * environment before the page ran, so an id carried from elsewhere is a 404.
     */
    private function organization(): ?Organization
    {
        $id = $this->scope->organizationId();

        return $id === null ? null : Organization::query()->find($id);
    }

    private function environment(EnvironmentContext $environments): ?Environment
    {
        $key = $environments->current()?->environmentKey();

        return $key === null ? null : Environment::query()->find($key);
    }
}
