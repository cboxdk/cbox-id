<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\OAuth\Contracts\AuthorizationOrganizations;
use App\Platform\Surfaces\ReturnUrl;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * In the Cbox realm nobody goes on to an app or the console without an organization.
 *
 * A person can end up with none: an administrator or a directory removed their last
 * membership, their only organization was closed, or they signed up before personal
 * organizations existed. Their account is still theirs, so they are not refused. They are
 * asked once, on one screen, to accept an invitation or continue with an organization of
 * their own, and then carry on to where they were going.
 *
 * The doors that ask this: `/oauth/authorize` before it binds an organization, and the
 * console's landings (`/dashboard`, the last-used organization, an organization's front
 * door). Off unless {@see PersonalOrganizations::enabled()}, except for the realm's own
 * (first-party) apps at `/oauth/authorize`, which are never given a person without one.
 */
final readonly class OrganizationRequired
{
    /** Set while this browser is held for one of the realm's own apps. */
    private const string HELD_KEY = 'cbox.organization_required_for_app';

    public function __construct(
        private PersonalOrganizations $personal,
        private AuthorizationOrganizations $organizations,
        private ConsoleScope $scope,
        private Impersonation $impersonation,
    ) {}

    /**
     * Whether this signed-in person must pass the "no organization" screen first.
     *
     * "No organization" is the answer apps get: no ACTIVE membership of a LIVE organization
     * in this environment. Never an operator, whose work is the deployment and needs
     * none, and never inside a support session, which must not found or join anything on
     * the person's behalf.
     *
     * With the switch on, or while this browser is held for one of the realm's own apps
     * ({@see holdForApp()}): the screen and its two answers must work for the person the
     * authorize endpoint sent there, whatever the switch says.
     */
    public function appliesTo(CurrentUser $me): bool
    {
        return ($this->personal->enabled() || $this->heldForApp()) && $this->hasNone($me);
    }

    /**
     * The same question at `/oauth/authorize`, for the app that is asking.
     *
     * ONE OF THE REALM'S OWN APPS IS NEVER HANDED A PERSON WITHOUT AN ORGANIZATION,
     * whatever the personal-organization switch says. A first-party app (Billing, Sites,
     * the console) keeps everything it holds under an organization; a code with none
     * signed the person in to an app that had nowhere to put them. With the switch off
     * that is what happened: the grant carried no organization and the app was left to
     * cope. The switch decides whether SIGNUP founds a personal organization; it does not
     * decide whether the realm's apps may be given nobody's.
     *
     * Any other client (a customer's, a self-registered one) is asked only with the
     * switch on, as before.
     */
    public function appliesToApp(CurrentUser $me, Client $client): bool
    {
        return ($this->personal->enabled() || ($client->first_party && $this->personal->inRealm()))
            && $this->hasNone($me);
    }

    /**
     * Note that this browser was sent to the screen for one of the realm's own apps, so
     * the screen answers with the switch off too. In the session, until the person has an
     * organization ({@see release()}).
     */
    public function holdForApp(): void
    {
        session()->put(self::HELD_KEY, true);
    }

    public function release(): void
    {
        session()->forget(self::HELD_KEY);
    }

    private function heldForApp(): bool
    {
        return session()->get(self::HELD_KEY) === true && $this->personal->inRealm();
    }

    private function hasNone(CurrentUser $me): bool
    {
        return $me->check()
            && ! $this->impersonation->isImpersonating()
            && ! $this->scope->isPlatformOperator()
            && $this->organizations->choicesFor($me->id()) === [];
    }

    /**
     * The screen's address. In login-host mode it is on the login host, and a console page
     * that sends somebody there says where to come back to.
     */
    public function url(): string
    {
        $request = request();

        return app(Surfaces::class)->is(Surface::Console) && $request->isMethod('GET')
            ? route('organization.required', [ReturnUrl::PARAMETER => $request->fullUrl()])
            : route('organization.required');
    }
}
