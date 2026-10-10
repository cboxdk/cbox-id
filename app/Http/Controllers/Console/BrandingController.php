<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Branding\AppearanceFields;
use App\Actions\Branding\SetAppearance;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\SaveAppearanceRequest;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRegistry;
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
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › BRANDING — everything a customer's people see of the brand, on ONE page: the
 * hosted sign-in theme (preset, colours, corners, typeface, logo and favicon, against a live
 * preview), and — where the white-label module is installed — the product's name, the email
 * sender and welcome mail, and the console palette.
 *
 * TWO PAGES USED TO DO THIS, and they overlapped. "Appearance" themed the sign-in and took a
 * logo URL; the module's "Branding" set a palette and uploaded a logo that nothing drew. The
 * rail read "Branding › Branding", and an administrator setting their logo had two places to
 * do it and one of them did nothing. Now there is one page, one logo, and old addresses
 * (`/appearance`, `/admin/appearance`, an organization's `…/appearance`) redirect here.
 *
 * EVERY WRITE IS STILL AN ACTION. The sign-in theme and images go through
 * `branding.appearance.set`; the name, sender, mail and palette through the module's own
 * `branding.whitelabel.set`, asked for BY NAME from the action registry — so this page needs
 * nothing from the module's classes, and without the module that section is simply absent.
 *
 * WHICH THING IS BEING BRANDED is the page's ADDRESS: the environment default every
 * organization inherits on the environment console's own Branding page, one organization's
 * own on its Branding tab (`/admin/organizations/{organization}/branding`) or on the
 * organization console. An organization administrator never reaches the environment
 * default — on the organization plane one tenant could otherwise re-brand every other
 * tenant's sign-in page — and the actions refuse it as well.
 */
final readonly class BrandingController extends ConsoleController
{
    public function edit(EnvironmentContext $environments): Response
    {
        $this->scope->assertMayAdminister();

        $mayThemeEnvironment = $this->scope->plane() === ConsolePlane::Environment;

        /*
         * WHICH THING IS BEING THEMED is the page's ADDRESS, not a choice on it. The
         * environment console's Branding page is the environment default every
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

        return $this->page('console/branding', 'Branding', [
            'help' => HelpProps::for(HelpTopic::Branding),
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
                : $this->url('branding.update'),
            /*
             * The white-label half — name, sender, welcome mail, console palette — read
             * through the module's own action, or null without the module (or without a
             * target). Its own form and its own Save, through its own action.
             */
            'profile' => $target === null ? null : $this->profile($environmentDefault ? null : $this->scope->organizationId()),
            'profileHref' => $mayThemeEnvironment && ! $environmentDefault
                ? route('environment.organizations.branding.profile.update')
                : $this->url('branding.profile.update'),
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
         * console's own Branding page, the organization on its Branding tab and on the
         * organization console. The form's `environmentDefault` is not trusted to choose —
         * its arrival on the organization plane is a forged payload, refused rather than
         * quietly downgraded, because treating a forgery as a typo is how a control stops
         * being one.
         */
        $environmentDefault = $this->scope->plane() === ConsolePlane::Environment && $this->scope->organizationId() === null;

        if ($request->environmentDefault()) {
            abort_unless($environmentDefault, 403,
                'Only an environment administrator may change the environment default theme, on the environment\'s own Branding page.');
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

        return back()->with('status', $environmentDefault ? 'Environment branding saved.' : 'Branding saved.');
    }

    /**
     * Save the white-label half — the product's name, the email sender, the welcome mail
     * and the console palette — through the module's own action, `branding.whitelabel.set`,
     * at the same altitude as the theme. A 404 without the module: there is nothing to save.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $action = $this->profileAction('branding.whitelabel.set');
        abort_if($action === null, 404);

        $environmentDefault = $this->scope->plane() === ConsolePlane::Environment && $this->scope->organizationId() === null;

        $request->validate([
            'palette' => ['array'],
            'palette.*' => ['nullable', 'string', 'max:100'],
            'appName' => ['nullable', 'string', 'max:120'],
            'emailFromName' => ['nullable', 'string', 'max:120'],
            'emailTemplate' => ['nullable', 'string', 'max:5000'],
        ]);

        $palette = [];

        foreach ((array) $request->input('palette', []) as $token => $value) {
            if (is_string($token)) {
                $palette[$token] = is_string($value) ? trim($value) : '';
            }
        }

        $blank = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $result = $this->act($action, [
            'organization_id' => $environmentDefault ? null : $this->scope->requireOrganizationId(),
            'palette' => $palette,
            'app_name' => $blank($request->input('appName')),
            'email_from_name' => $blank($request->input('emailFromName')),
            'email_template' => is_string($request->input('emailTemplate')) ? $request->input('emailTemplate') : '',
        ], [
            ...array_combine(
                array_map(static fn (string $token): string => 'palette.'.$token, array_keys($palette)),
                array_map(static fn (string $token): string => 'palette.'.$token, array_keys($palette)),
            ),
            'app_name' => 'appName',
            'email_from_name' => 'emailFromName',
            'email_template' => 'emailTemplate',
        ], 'appName');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', 'Branding saved.');
    }

    /**
     * The white-label profile at this altitude, as the page edits it, or null without the
     * module. Read through the module's own read action, by name.
     *
     * @return array{tokens: list<string>, palette: array<string, string>, appName: string, emailFromName: string, emailTemplate: string}|null
     */
    private function profile(?string $organizationId): ?array
    {
        $action = $this->profileAction('branding.whitelabel.get');

        if ($action === null) {
            return null;
        }

        $payload = $this->runAction($action, $organizationId === null ? [] : ['organization_id' => $organizationId])->payload ?? [];

        $palette = [];

        foreach ((array) ($payload['palette'] ?? []) as $token => $value) {
            if (is_string($token)) {
                $palette[$token] = is_string($value) ? $value : '';
            }
        }

        $text = static fn (mixed $value): string => is_string($value) ? $value : '';

        return [
            'tokens' => array_keys($palette),
            'palette' => $palette,
            'appName' => $text($payload['app_name'] ?? null),
            'emailFromName' => $text($payload['email_from_name'] ?? null),
            'emailTemplate' => $text($payload['email_template'] ?? null),
        ];
    }

    /**
     * An action of the white-label module, by name, or null when the module is not here.
     *
     * @return class-string<Action>|null
     */
    private function profileAction(string $name): ?string
    {
        $all = app(ActionRegistry::class)->all();

        return isset($all[$name]) ? $all[$name]->class : null;
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
