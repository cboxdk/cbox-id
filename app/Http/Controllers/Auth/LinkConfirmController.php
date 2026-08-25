<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Middleware\Authenticate;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * "DO YOU WANT TO CONNECT THIS ACCOUNT?" — the last step of a social sign-in whose
 * address already belonged to somebody here.
 *
 * This screen exists because both alternatives are wrong. Merging the two accounts
 * because the addresses match trusts the provider's word for an address, which we have
 * decided we cannot: Discord will carry whatever somebody typed. Refusing outright leaves
 * the legitimate owner — who really does own both — with no way through.
 *
 * So we ask. By the time this renders, three separate things are true: the person
 * completed the provider's sign-in, they authenticated HERE, and they are about to say so
 * out loud. That is strictly more than address equality ever proved, and it does not care
 * that a GitHub account may carry five addresses.
 *
 * The hold in {@see Authenticate} keeps every authenticated request
 * on this page until one of the two answers is given, so a link never sits half-made.
 */
final readonly class LinkConfirmController extends PageController
{
    public function show(PlatformAuth $auth, CurrentUser $me): Response|RedirectResponse
    {
        $pending = $auth->pendingLink($me->id());

        // Nothing waiting — somebody typed the URL, or answered in another tab. Not an
        // error worth a page: send them where they were going.
        if ($pending === null) {
            return to_route('dashboard');
        }

        return $this->page('auth/link-confirm', 'Connect your account', [
            'provider' => $pending->label(),
            'email' => $pending->email,
            'name' => $pending->name,
        ]);
    }

    public function connect(PlatformAuth $auth, CurrentUser $me): RedirectResponse
    {
        /*
         * READ THE SESSION, not the request.
         *
         * Which provider this is was displayed to the person, and display copies travel
         * to the browser and come back. The decision is made against the pending link the
         * session holds, which the browser cannot reach — otherwise editing one field in
         * the payload would be enough to describe one link on screen and create another.
         */
        $pending = $auth->pendingLink($me->id());
        $label = $pending?->label() ?? 'That provider';

        $linked = $auth->confirmPendingLink($me->id());

        return to_route('account')->with(
            $linked ? 'status' : 'error',
            $linked
                ? $label.' is now connected to your account. You can sign in with it from now on.'
                : 'That connection could not be completed. It may have expired or already be connected to another account.',
        );
    }

    public function decline(PlatformAuth $auth): RedirectResponse
    {
        $auth->discardPendingLink();

        /*
         * Worth saying plainly. Somebody seeing this screen who did NOT just sign in with
         * that provider is looking at evidence that another person tried to, using their
         * address. Declining is the right answer, and the message says what it means.
         */
        return to_route('dashboard')->with('status', 'Not connected. Nothing was changed on your account.');
    }
}
