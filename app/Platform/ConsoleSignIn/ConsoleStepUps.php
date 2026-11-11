<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\EnvironmentSudo;
use App\Platform\Operators\OperatorAssurance;
use App\Platform\StepUpPlane;
use App\Platform\StepUpPrompt;
use App\Platform\Sudo;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Enums\AuthenticationContextClass;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationRequirement;
use Illuminate\Http\Request;

/**
 * STEP-UP BY REDIRECT (login-host mode): the console never asks for a password or a
 * passkey itself.
 *
 * A gated action sends the browser to the login host with `max_age` (and `acr_values`
 * where the plane names a class), the login host re-authenticates the person with
 * whatever they sign in with, and the console opens the plane's window from the
 * `auth_time` and `acr` of the ID token that comes back. The window is counted from
 * `auth_time`, not from the moment the browser returned.
 *
 * WHAT EACH PLANE REQUIRES IS CONFIGURATION, keyed by the plane's label
 * (`cbox-id.console.step_up`): `max_age` for all of them, and an `acr` per plane. A plane
 * with a stronger proof (the operator plane's phishing-resistant class) is a line there,
 * not a second flow. An `acr` this deployment's framework does not know is never met.
 *
 * WHICH ENVIRONMENT an environment-plane step-up is for is recorded by the gate that
 * asked ({@see StepUpPrompt::demand()}), in the session. It is never read
 * from the URL, so a link cannot open the window for an environment nobody was asked in.
 */
final class ConsoleStepUps
{
    public function __construct(
        private readonly Sudo $organization,
        private readonly EnvironmentSudo $environment,
    ) {}

    /** The session key the gate records the environment under, for a plane. */
    public static function environmentKey(StepUpPlane $plane): string
    {
        return $plane->value.'.environment';
    }

    /** Called by the gate: remember where the step-up was asked. */
    public function remember(Request $request, StepUpPlane $plane): void
    {
        $environment = app(EnvironmentContext::class)->current()?->environmentKey();

        if ($environment !== null) {
            $request->session()->put(self::environmentKey($plane), $environment);
        }
    }

    /** What to ask the login host for, for this plane. */
    public function request(Request $request, StepUpPlane $plane): ConsoleStepUpRequest
    {
        // The operator plane's proof is a passkey; how long one opens the plane and how
        // fresh a dangerous action needs it is that plane's own clock ({@see OperatorAssurance}).
        // What is asked of the login host is the same for all of them: authenticate now.
        $maxAge = config('cbox-id.console.step_up.max_age', 0);
        $acr = config('cbox-id.console.step_up.acr.'.$plane->label());
        $environment = $request->session()->get(self::environmentKey($plane));

        return new ConsoleStepUpRequest(
            plane: $plane,
            maxAge: is_numeric($maxAge) ? max(0, (int) $maxAge) : 0,
            acr: is_string($acr) && $acr !== '' ? $acr : null,
            environment: is_string($environment) && $environment !== '' ? $environment : null,
        );
    }

    /**
     * Open the plane's window if the returned authentication is what was asked for.
     * Returns whether it was opened.
     */
    public function open(Request $request, PendingSignIn $pending, ConsoleIdentity $identity): bool
    {
        $plane = $pending->plane;

        if ($plane === null || $identity->authTime === null) {
            return false;
        }

        if ($plane->label() === 'operator') {
            // The operator plane's proof is read from HOW the person authenticated, by the
            // plane's own keeper: a passkey with user verification. The login host states
            // the matching `acr` only where the deployment has turned that on.
            $met = AuthenticationRequirement::of(null, $pending->maxAge ?? 0)
                ->assess($identity->acr, $identity->authTime)
                ->isSatisfied()
                && app(OperatorAssurance::class)->reached($identity->amr);
        } else {
            // A class the framework cannot name would be dropped from the requirement, and
            // a dropped requirement is one that passes: refuse it here instead.
            if ($pending->acr !== null && AuthenticationContextClass::tryFrom($pending->acr) === null) {
                return false;
            }

            $met = AuthenticationRequirement::of($pending->acr, $pending->maxAge ?? 0)
                ->assess($identity->acr, $identity->authTime)
                ->isSatisfied();
        }

        if (! $met) {
            return false;
        }

        $request->session()->forget(self::environmentKey($plane));

        // By label, so a plane added after this was written is one more arm here.
        return match ($plane->label()) {
            'organization' => $this->openOrganization($identity->authTime),
            'environment' => $this->openEnvironment($pending->environment, $identity->authTime),
            'operator' => $this->openOperator($identity->authTime),
            default => false,
        };
    }

    private function openOrganization(int $authTime): bool
    {
        $this->organization->confirm($authTime);

        return true;
    }

    private function openEnvironment(?string $environment, int $authTime): bool
    {
        if ($environment === null) {
            return false;
        }

        $this->environment->confirm($environment, $authTime);

        return true;
    }

    /** The operator plane's passkey proof has a keeper of its own; it opens from the same `auth_time`. */
    private function openOperator(int $authTime): bool
    {
        app(OperatorAssurance::class)->confirm($authTime);

        return true;
    }
}
