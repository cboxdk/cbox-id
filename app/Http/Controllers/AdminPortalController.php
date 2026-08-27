<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AdminPortal;
use Illuminate\Http\RedirectResponse;

/**
 * Guest entry point for an Admin Portal setup link. It redeems the token and,
 * on success, redirects into the scoped setup screen; on any failure it shows a
 * friendly "expired or already used" page with no enumeration detail.
 *
 * Thin by design: all redemption logic (hashing, validity, entitlement re-check,
 * establishing the scoped session) lives in {@see AdminPortal}.
 */
final class AdminPortalController extends Controller
{
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
