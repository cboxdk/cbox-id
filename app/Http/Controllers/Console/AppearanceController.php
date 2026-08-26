<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\SaveAppearanceRequest;
use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\ThemeFont;
use App\Platform\Appearance\ThemePresets;
use App\Platform\Appearance\ThemeRadius;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Organizations;
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
 * That gate is on both now.
 */
final readonly class AppearanceController extends ConsoleController
{
    public function edit(EnvironmentContext $environments): Response
    {
        $this->scope->assertMayAdminister();

        $mayThemeEnvironment = $this->scope->plane() === ConsolePlane::Environment;

        /*
         * WHICH THING IS BEING THEMED, on the environment plane, is a choice — and its
         * landing state there is the environment default, because that is the capability
         * that console's page WAS. A merge that silently retargeted "Appearance" at
         * whichever organization happened to be picked would have an operator re-theming
         * one tenant while believing they were setting the default for all of them.
         */
        $environmentDefault = $mayThemeEnvironment
            && request()->string('target')->toString() !== 'organization';

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
            'radii' => ThemeRadius::values(),
            // THE VIEW HALF asks the SCOPE rather than assuming the plane it was written
            // for, so the control the page draws and the write the server accepts come
            // from one rule.
            'mayThemeEnvironment' => $mayThemeEnvironment,
            'environmentDefault' => $environmentDefault,
            'organizationName' => $organization?->name,
            // Nothing to theme: an environment administrator who has not chosen an
            // organization, or a member who belongs to none.
            'hasTarget' => $target !== null,
            // WHERE SAVE POSTS, resolved by the server: one controller action serves two
            // route names, so the page cannot work out which plane it is on by itself.
            'saveHref' => $this->url('appearance.update'),
        ]);
    }

    public function update(
        SaveAppearanceRequest $request,
        Organizations $organizations,
        EnvironmentContext $environments,
    ): RedirectResponse {
        $this->scope->assertMayAdminister();

        $appearance = Appearance::fromArray($request->theme());

        /*
         * REFUSE AN UNREADABLE PALETTE rather than warn about one.
         *
         * `hex()` validates the FORMAT, so nothing stopped a "light" mode with a
         * near-black background — and the people who then cannot read the sign-in page are
         * not the administrator choosing the colours, they are that organization's users.
         * A warning the saver can click past puts the consequence on somebody who never
         * saw it.
         */
        $failures = array_merge(
            array_map(fn (string $why): string => 'Light mode: '.$why, $appearance->light->contrastFailures()),
            array_map(fn (string $why): string => 'Dark mode: '.$why, $appearance->dark->contrastFailures()),
        );

        if ($failures !== []) {
            return back()->withErrors(['theme' => implode(' ', $failures)]);
        }

        $payload = [
            'appearance' => $appearance->toArray(),
            'brand_color' => $appearance->light->primary,
            'brand_logo_url' => $request->logo(),
        ];

        if ($request->environmentDefault()) {
            /*
             * Refused rather than quietly downgraded to the organization: the control is
             * not rendered on the organization plane, so its arrival there is a forged
             * payload, and treating a forgery as a typo is how a control stops being one.
             */
            abort_unless($this->scope->plane() === ConsolePlane::Environment, 403,
                'Only an environment administrator may change the environment default theme.');

            $environment = $this->environment($environments);

            if ($environment === null) {
                return back();
            }

            $environment->settings = array_merge($environment->settings, $payload);
            $environment->save();

            return back()->with('status', 'Environment appearance saved.');
        }

        // `requireOrganizationId()`, not the nullable reader: with none resolved this
        // write would otherwise land wherever a downstream default pointed.
        $organizations->updateSettings($this->scope->requireOrganizationId(), $payload);

        return back()->with('status', 'Appearance saved.');
    }

    /**
     * The editor's starting state — whichever thing is currently being themed.
     *
     * @return array<string, mixed>
     */
    private function seed(Environment|Organization|null $target): array
    {
        $settings = $target === null ? [] : $target->settings;

        return [
            ...Appearance::fromSettings($settings)->toArray(),
            'logo' => is_string($settings['brand_logo_url'] ?? null) ? $settings['brand_logo_url'] : '',
            'name' => $target === null ? '' : $target->name,
        ];
    }

    /**
     * The organization being themed — the SCOPE's, never a form field's.
     *
     * On the organization plane it is the member's own and nothing in the request can
     * change it; on the environment plane the scope re-validates the chosen id against
     * this environment on every read, so an id carried from elsewhere resolves to nothing.
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
