<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use App\Http\Props\Shared\HelpProps;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Whitelabel\Actions\SaveBranding;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Http\Requests\SaveBrandingRequest;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Cbox\Id\Whitelabel\Support\PaletteTokens;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * CONSOLE › BRANDING — one page, both planes, at TWO ALTITUDES.
 *
 * The brand-profile table has always had both: a row per organization, and one row with
 * `organization_id IS NULL` that every organization in the environment inherits when it has
 * none of its own. The page could only ever be opened by an organization admin, so the
 * environment default — the altitude the schema was built around — had no editor anywhere in
 * the console, and an earlier fix could only close the hole by pinning this page to the
 * organization altitude and leaving the other unreachable.
 *
 * WHICH ALTITUDE IS EDITED IS THE SCOPE'S ANSWER AND NOTHING ELSE. On the organization plane
 * the scope refuses to resolve null, so that plane can only ever reach its own row — one
 * tenant re-branding the sign-in page of every other tenant in the environment remains
 * impossible. On the environment plane, no organization chosen means the environment
 * default, which is precisely what that administrator owns.
 */
final readonly class BrandingController extends ConsoleController
{
    public function index(BrandImages $images): Response
    {
        $this->scope->assertMayAdminister();

        $profile = $this->profile();

        $palette = array_fill_keys(PaletteTokens::TOKENS, '');

        foreach (PaletteTokens::TOKENS as $token) {
            // No profile yet: every field stays blank and the form renders as "not
            // branded", which is a different state from branded-then-cleared.
            $palette[$token] = $profile?->palette->get($token) ?? '';
        }

        return $this->page('whitelabel::branding', 'Branding', [
            'help' => HelpProps::for(HelpTopic::Branding),
            'tokens' => PaletteTokens::TOKENS,
            'palette' => $palette,
            // The columns are nullable, so the coalesce is doing the work and the nullsafe
            // would be doing it twice — a profile that is absent takes the branch above.
            'appName' => $profile === null ? '' : ($profile->app_name ?? ''),
            'emailFromName' => $profile === null ? '' : ($profile->email_from_name ?? ''),
            'emailTemplate' => $profile?->email_templates->get('welcome') ?? '',
            /*
             * SHOWN HERE, UPLOADED ON APPEARANCE. The logo and favicon are the hosted
             * sign-in's, so they are edited beside the theme they are previewed in — one
             * upload, through the action the management API and MCP run
             * (`branding.appearance.set`), rather than a second form that wrote the same
             * columns with no API at all. Read through the host's socket so the URL is the
             * one the hosted pages draw.
             */
            'logoUrl' => $images->url(BrandImage::Logo, $this->scope->organizationId()),
            'faviconUrl' => $images->url(BrandImage::Favicon, $this->scope->organizationId()),
            'appearanceHref' => $this->scope->plane() === ConsolePlane::Environment && $this->scope->organizationId() !== null
                ? route('environment.organizations.branding')
                : $this->url('appearance'),
            /*
             * The view half of the altitude. A page that edits one organization's brand while
             * telling the reader it themes "this whole environment" is how a tenant admin
             * comes to believe they changed something they did not.
             */
            'environmentDefault' => $this->scope->organizationId() === null,
            // One organization's own brand is a profile of its own, on its own page — so on
            // the environment default the Organization chip goes there.
            'organizationFilter' => $this->organizationJump('environment.organizations.whitelabel.branding'),
            'saveHref' => $this->url('whitelabel.branding.save'),
        ]);
    }

    /**
     * Save through the ACTION the management API runs ({@see SaveBranding}) — the palette
     * check and the altitude rule are the action's.
     *
     * THE ALTITUDE THE SCOPE RESOLVES, and no other. This used to read and write the
     * `organization_id IS NULL` row unconditionally behind an ORG-admin check, so an admin of
     * one tenant re-branded the console and the hosted sign-in page for every other tenant;
     * it was then pinned to the organization, which left the environment default with no
     * editor. The scope answers null only on the environment plane, and the action refuses
     * any other organization than the scope's on the organization plane.
     *
     * The logo and favicon are no longer uploaded here. They are the hosted sign-in's and
     * live on the Appearance page, where `branding.appearance.set` takes them — so the one
     * upload path is an action the API and MCP can reach too.
     */
    public function save(SaveBrandingRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->organizationId();
        $palette = [];

        foreach (PaletteTokens::TOKENS as $token) {
            $palette[$token] = $request->palette()[$token] ?? '';
        }

        $result = $this->act(SaveBranding::class, [
            'organization_id' => $organizationId,
            'palette' => $palette,
            'app_name' => $request->appName(),
            'email_from_name' => $request->emailFromName(),
            'email_template' => $request->emailTemplate(),
        ], [
            ...array_combine(
                array_map(static fn (string $token): string => 'palette.'.$token, PaletteTokens::TOKENS),
                array_map(static fn (string $token): string => 'palette.'.$token, PaletteTokens::TOKENS),
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
     * The profile at the altitude currently being edited, or null when there is none yet.
     *
     * NEVER A VALUE FROM THE REQUEST. On the organization plane the scope answers the
     * session's own organization or refuses outright — it cannot answer null — so that plane
     * can only ever reach its own row. Null therefore means exactly one thing here: an
     * environment administrator editing the environment's default.
     */
    private function profile(): ?BrandProfile
    {
        $organizationId = $this->scope->organizationId();

        return $organizationId === null
            ? app(BrandProfiles::class)->forEnvironment()
            : app(BrandProfiles::class)->forOrganization($organizationId);
    }
}
