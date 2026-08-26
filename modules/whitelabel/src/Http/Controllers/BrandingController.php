<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use Cbox\Id\Whitelabel\Assets\BrandAssetStore;
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
    public function index(): Response
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
            'tokens' => PaletteTokens::TOKENS,
            'palette' => $palette,
            // The columns are nullable, so the coalesce is doing the work and the nullsafe
            // would be doing it twice — a profile that is absent takes the branch above.
            'appName' => $profile === null ? '' : ($profile->app_name ?? ''),
            'emailFromName' => $profile === null ? '' : ($profile->email_from_name ?? ''),
            'emailTemplate' => $profile?->email_templates->get('welcome') ?? '',
            /*
             * SERVER-DERIVED, and only ever read back from here. These were bound properties
             * once, and a client that can set them can name another environment's asset and
             * have the next upload delete it — or point the environment's logo at a host of
             * its choosing, which is a beacon on every branded page.
             */
            'logoUrl' => $profile?->logo_url,
            'faviconUrl' => $profile?->favicon_url,
            /*
             * The view half of the altitude. A page that edits one organization's brand while
             * telling the reader it themes "this whole environment" is how a tenant admin
             * comes to believe they changed something they did not.
             */
            'environmentDefault' => $this->scope->organizationId() === null,
            'saveHref' => $this->url('whitelabel.branding.save'),
        ]);
    }

    public function save(SaveBrandingRequest $request, BrandProfiles $profiles, BrandAssetStore $assets): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $clean = [];

        foreach (PaletteTokens::TOKENS as $token) {
            $value = $request->palette()[$token] ?? '';

            if ($value === '') {
                continue;
            }

            if (! PaletteTokens::isValidColor($value)) {
                return back()->withInput()->withErrors([
                    'palette.'.$token => 'Use a hex (#0a2540) or oklch(...) colour.',
                ]);
            }

            $clean[$token] = $value;
        }

        /*
         * THE ALTITUDE THE SCOPE RESOLVES, and no other.
         *
         * This used to read and write `forEnvironment()` unconditionally — the
         * `organization_id IS NULL` row every organization inherits — behind an ORG-admin
         * check, so an admin of one tenant re-branded the console and the hosted sign-in page
         * for every other tenant in the environment. It was then pinned to the organization,
         * which closed that and left the environment default with no editor.
         */
        $organizationId = $this->scope->organizationId();
        $profile = $this->profile() ?? new BrandProfile(['organization_id' => $organizationId]);

        $logoUrl = $profile->logo_url;
        $faviconUrl = $profile->favicon_url;

        if ($request->file('logo') !== null) {
            $assets->forget($profile->logo_url);
            $logoUrl = $assets->put('logo', $request->file('logo'));
        }

        if ($request->file('favicon') !== null) {
            $assets->forget($profile->favicon_url);
            $faviconUrl = $assets->put('favicon', $request->file('favicon'));
        }

        $profile->fill([
            'organization_id' => $organizationId,
            'palette' => $clean,
            'app_name' => $request->appName(),
            'email_from_name' => $request->emailFromName(),
            'email_templates' => $profile->email_templates->with('welcome', $request->emailTemplate()),
            'logo_url' => $logoUrl,
            'favicon_url' => $faviconUrl,
        ]);

        $profiles->save($profile);

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
