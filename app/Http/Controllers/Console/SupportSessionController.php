<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\StartSupportSessionRequest;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\SupportAccess\Contracts\SupportAccess;
use App\Platform\SupportAccess\ValueObjects\SupportSignInRequest;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Enums\SupportSessionRefusal;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Exceptions\SupportSessionRefused;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ENVIRONMENT CONSOLE › A USER › "SIGN IN TO <APP> AS <USER>" — support access.
 *
 * Starting one hands the administrator's browser to the app, which starts its own sign-in;
 * the authorization endpoint answers it with a code for the session. Everything the
 * session is — who, which organization, which app, why, until when — is decided here and
 * checked again by the framework. See {@see SupportAccess}.
 */
final readonly class SupportSessionController extends ConsoleController
{
    public function store(
        StartSupportSessionRequest $request,
        string $user,
        SupportAccess $support,
        Memberships $memberships,
    ): Response {
        $actorId = $this->administrator();

        // Through the environment-scoped model: a person from another environment is
        // nobody here.
        $target = User::query()->whereKey($user)->first();
        abort_if($target === null, 404);

        /*
         * ONLY AN APP SUPPORT MAY REACH — first-party, owned by this environment, signing
         * people in with the authorization-code grant. The person being acted as never
         * agrees to a support session, so it may only reach an app that would not have
         * asked them anyway. The framework refuses anything else too; asking here first
         * means the refusal names the field rather than failing after the step-up.
         */
        if (! $support->isEligible($request->clientId())) {
            return back()->withInput()->withErrors([
                'app' => 'Support sessions reach only first-party apps this environment owns.',
            ]);
        }

        // One of THEIR organizations, and a live membership in it: acting as somebody in an
        // organization they were never let into — or were suspended from — would put them
        // there on the console's say-so.
        $membership = $memberships->of($request->organizationId(), $target->id);

        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            return back()->withInput()->withErrors([
                'organization' => 'They are not an active member of that organization.',
            ]);
        }

        /*
         * A FRESH PASSWORD FIRST. This mints tokens that act as somebody else, in their
         * organization, in an app — the same class of act as setting their password, which
         * asks too. After validation, so a malformed form is answered with its error and not
         * with a password prompt.
         */
        $challenge = app(ConsoleStepUp::class)->challenge(
            'environment.users.show',
            'environment.users.show',
            ['user' => $target->id],
            'Signing in to an app as this person lets you act as them there.',
        );

        if ($challenge !== null) {
            return to_route($challenge);
        }

        try {
            $launch = $support->start(new SupportSignInRequest(
                actorId: $actorId,
                targetUserId: $target->id,
                organizationId: $request->organizationId(),
                clientId: $request->clientId(),
                reason: $request->reason(),
                minutes: $request->minutes(),
            ));
        } catch (SupportSessionRefused $refused) {
            return back()->withInput()->withErrors([$this->field($refused->refusal) => $refused->getMessage()]);
        } catch (InvalidAudience $audience) {
            // The app's scopes cannot be audienced to one API: no sign-in it starts could
            // finish. The framework refuses it before the session exists.
            return back()->withInput()->withErrors(['app' => $audience->getMessage()]);
        }

        // To the app, which starts its own sign-in. A real navigation even from an Inertia
        // visit: the destination is another origin.
        return $this->inertia->location($launch);
    }

    public function destroy(string $session, SupportAccess $support): RedirectResponse
    {
        $actorId = $this->administrator();

        abort_unless($support->end($session, $actorId), 404);

        return back()->with('status', 'Support session ended — every token it issued is revoked.');
    }

    /** The administrator acting, as a subject of the platform root. */
    private function administrator(): string
    {
        $actorId = app(EnvironmentAdminAuth::class)->subjectId();

        abort_if($actorId === null, 403);

        return $actorId;
    }

    /** Which field on the form a framework refusal belongs under. */
    private function field(SupportSessionRefusal $refusal): string
    {
        return match ($refusal) {
            SupportSessionRefusal::ReasonRequired => 'reason',
            SupportSessionRefusal::OrganizationInactive,
            SupportSessionRefusal::TargetNotMember,
            SupportSessionRefusal::SelfImpersonation => 'organization',
            default => 'app',
        };
    }
}
