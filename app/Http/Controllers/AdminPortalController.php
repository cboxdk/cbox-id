<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AdminPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

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
    public function enter(string $token, AdminPortal $portal): RedirectResponse|Response
    {
        if ($portal->redeem($token) === null) {
            return response()->view('portal.expired', [], 410);
        }

        return redirect()->route('portal.setup');
    }
}
