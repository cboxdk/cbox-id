<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Props\Auth\LinkConfirmationProps;
use App\Platform\AdminPortal;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Guest entry point for an Admin Portal setup link. It redeems the token and,
 * on success, redirects into the scoped setup screen; on any failure it shows a
 * friendly "expired or already used" page with no enumeration detail.
 *
 * Thin by design: all redemption logic (hashing, validity, entitlement re-check,
 * establishing the scoped session) lives in {@see AdminPortal}.
 *
 * OPENING THE LINK SPENDS NOTHING. The link is single-use and it is handed to somebody
 * outside the organization through whatever channel the administrator had to hand — mail,
 * Slack, Teams — every one of which fetches a URL to preview it. On a GET that fetch was
 * the redemption: the unfurler got the setup session and the IT admin got "expired". The
 * GET renders a button now, and the POST redeems.
 */
final readonly class AdminPortalController extends PageController
{
    public function show(string $token): Response
    {
        return $this->page('auth/open-portal-setup', 'Set up single sign-on', [
            'confirmation' => new LinkConfirmationProps(
                heading: 'Set up sign-in for your organization',
                lead: 'You were sent a setup link to connect your identity provider or directory. Continue to open the setup screen.',
                actionLabel: 'Open setup',
                actionUrl: route('portal.enter.store', $token),
                note: 'The link works once, and the setup session it opens expires. Open it when you are ready to finish.',
            ),
        ]);
    }

    public function enter(string $token, AdminPortal $portal): RedirectResponse
    {
        /*
         * A REDIRECT rather than a 410 body. The refusal page is a rendered page now, and
         * rendering it from here would mean serving it at the token's own URL — putting a
         * spent, single-use secret in the address bar of a page somebody is likely to leave
         * open, and in whatever their browser syncs. The status is lost and the sentence is
         * not, which is the half that matters to the person reading it.
         */
        if ($portal->redeem($token) === null) {
            return redirect()->route('portal.expired');
        }

        return redirect()->route('portal.setup');
    }
}
