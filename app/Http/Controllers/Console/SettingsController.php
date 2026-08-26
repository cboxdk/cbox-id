<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\RenameOrganizationRequest;
use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\ThemePresets;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * CONSOLE › SETTINGS — what the administrator is administering, and the details an
 * integrator copies to wire sign-in up.
 *
 * ONE PAGE, BOTH PLANES, and the pair looked like it might not be one because the two
 * pages were about different objects: the organization page was the ORGANIZATION's record
 * — rename, slug, type, id — and the environment page was the ENVIRONMENT's identity plus
 * its OIDC issuer and discovery URL. Neither offered what the other did.
 *
 * The console's own rule says how they merge. An administrator of the environment and an
 * administrator of a tenant see the same product, and the difference is what the
 * environment holds IN ADDITION. So: the organization's settings are here on both planes,
 * bounded by whichever organization the scope resolves; the environment's identity is here
 * on the environment plane alone, because that record is the control plane's; and the
 * integration block is on both, because the organization admin wiring an app is exactly
 * who needed it and had nowhere to find it.
 */
final readonly class SettingsController extends ConsoleController
{
    public function show(EnvironmentContext $environments): Response|RedirectResponse
    {
        if (! $this->scope->mayAdminister()) {
            /*
             * The organization plane's own courtesy, kept rather than flattened into a
             * 403: a member who lands here from a bookmark has a security centre of their
             * own, and this page is not a read-only echo of controls they cannot use.
             */
            if ($this->scope->plane() === ConsolePlane::Organization) {
                return to_route('account');
            }

            $this->scope->assertMayAdminister();
        }

        $onEnvironmentPlane = $this->scope->plane() === ConsolePlane::Environment;
        $organization = $this->organization();
        $environment = $onEnvironmentPlane ? $this->environment($environments) : null;

        /*
         * ASKED, NOT REBUILT.
         *
         * This was `'https://'.request()->getHost()`, which is right for a tenant
         * environment on its own subdomain and right only by coincidence: the platform
         * root and the single-tenant shape keep the CONFIGURED issuer even when they
         * answer on another host, so the two part company exactly where nobody is looking.
         * One value with three derivations is three chances to hand a developer something
         * their SDK cannot verify against.
         */
        $issuer = rtrim(app(IssuerResolver::class)->issuer(), '/');

        // Whose theme the branding card previews: the acting organization's own, or the
        // environment default it would inherit when there is no organization to speak of.
        $brandingTarget = $organization ?? $environment;
        $appearance = Appearance::fromSettings(
            $brandingTarget === null ? [] : $brandingTarget->settings,
        );

        return $this->page('console/settings', 'Settings', [
            'help' => HelpProps::for(HelpTopic::Settings),
            'organization' => $organization === null ? null : [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'type' => ucfirst($organization->type->value),
                'brandedLoginHref' => route('login.branded', $organization->slug),
            ],
            /*
             * The environment's own record, on the plane that holds it. An organization
             * administrator has no business reading the control plane's identity, and no
             * way to act on it if they did.
             *
             * `environmentRecord`, NOT `environment`: the shell shares a prop by that name
             * — the realm this request is in, which the sandbox banner and the badge read
             * — and a page prop of the same name replaces it. This page sent null for it
             * on the organization plane, and the CHROME threw on a page that was itself
             * perfectly correct. {@see PageController::page()} now refuses the collision
             * outright.
             */
            'environmentRecord' => $environment === null ? null : [
                'id' => $environment->id,
                'name' => $environment->name,
                'sandbox' => $environment->isSandbox(),
            ],
            'appearance' => [
                'preset' => ThemePresets::get($appearance->preset)->label,
                'lightBackground' => $appearance->light->background,
                'lightPrimary' => $appearance->light->primary,
                'darkBackground' => $appearance->dark->background,
                'darkPrimary' => $appearance->dark->primary,
            ],
            'appearanceHref' => $this->url('appearance'),
            'renameHref' => $this->url('settings.rename'),
            'accountHref' => route('account'),
            /*
             * The guided first run is an ORGANIZATION's, and its route only answers to a
             * subject session — so it is offered where it can actually be opened.
             */
            'setupGuideHref' => ! $onEnvironmentPlane && $organization !== null
                ? route('get-started')
                : null,
            'issuer' => $issuer,
            'discovery' => $issuer.'/.well-known/openid-configuration',
        ]);
    }

    /**
     * Rename the organization the console is acting on.
     *
     * New to the environment plane, which could administer every organization in the
     * environment and not correct a typo in one's name without signing into its console.
     */
    public function rename(RenameOrganizationRequest $request, AuditLog $audit): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // `requireOrganizationId()`, not the nullable reader: with none resolved this
        // write would otherwise rename whichever organization a downstream default picked.
        $organization = Organization::query()->findOrFail($this->scope->requireOrganizationId());

        $to = $request->name();

        if ($to === $organization->name) {
            return back();
        }

        $from = $organization->name;
        $organization->forceFill(['name' => $to])->save();

        // The SCOPE's actor id, not the console's own idea of who is acting: the two
        // planes recorded ids from different tables for the same act.
        $audit->record(AuditEvent::forUser('organization.renamed', $this->scope->actorId(), $organization->id, [
            'from' => $from,
            'to' => $to,
        ]));

        return back()->with('status', 'Organization name updated.');
    }

    /**
     * The organization being administered — the SCOPE's, never a form field's.
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
