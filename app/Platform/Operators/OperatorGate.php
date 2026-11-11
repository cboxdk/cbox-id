<?php

declare(strict_types=1);

namespace App\Platform\Operators;

use App\Platform\StepUpPlane;
use App\Platform\StepUpPrompt;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * How the operator plane ASKS for its passkey ({@see OperatorAssurance} decides whether
 * one is owed).
 *
 * It asks the way every step-up in the console does ({@see StepUpPrompt}): in place for a
 * request a script made, which the page replays once the passkey is confirmed, and on the
 * full-page screen for a document navigation. Only the proof differs: a WebAuthn ceremony
 * instead of a password.
 */
final readonly class OperatorGate
{
    /**
     * Actions that need a fresh passkey on the plane although their {@see \App\Platform\Actions\Danger}
     * is only `Write`. Each one opens a way to sign in to the realm every first-party app
     * trusts: a draft SSO connection is the first half of a new identity provider, and
     * turning a social provider back on is a new door at once. For a customer's own
     * environment they stay ordinary writes, which is why the class is not raised instead.
     *
     * @var list<string>
     */
    public const array SIGN_IN_SETTINGS = ['sso.connections.create', 'signin.social.enable'];

    public function __construct(private OperatorAssurance $assurance) {}

    /** Whether an action with this name and danger needs a fresh proof on the plane. */
    public static function guards(string $action, bool $destructive): bool
    {
        return $destructive || in_array($action, self::SIGN_IN_SETTINGS, true);
    }

    /**
     * The standing check every request on the plane passes. Null when the request may go
     * on; otherwise the response that asks for the passkey (or thrown, for a script).
     *
     * @throws HttpResponseException
     */
    public function admit(Request $request): ?Response
    {
        if (! $this->assurance->enabled() || $request->routeIs('operator.sudo', 'operator.sudo.*')) {
            return null;
        }

        $standing = $this->assurance->standing();

        if ($standing === OperatorStanding::Admitted) {
            $this->assurance->touch();

            return null;
        }

        if ($standing->confirmable() && $this->assurance->confirms()) {
            return StepUpPrompt::demand($request, StepUpPlane::Operator, reason: $standing->message());
        }

        // Nothing a ceremony HERE can fix (no passkey enrolled, user verification off, or
        // the passkey lives on the sign-in host):
        // the screen that says what to do, never a dialog asking for a passkey that is
        // not there.
        if ($request->header('X-Inertia') !== null) {
            throw new HttpResponseException(Inertia::location(route('operator.sudo')));
        }

        abort_if(StepUpPrompt::inPlace($request), 403, $standing->confirmable() ? $this->assurance->elsewhereMessage() : $standing->message());

        return redirect()->route('operator.sudo');
    }

    /**
     * A dangerous act on the plane: refuse it unless the passkey proof is fresh. A no-op
     * where the rule does not govern.
     *
     * Thrown either way, because the callers are in the middle of a controller with no
     * response of their own to return through.
     *
     * @throws HttpResponseException
     */
    public function requireFresh(Request $request, string $reason): void
    {
        if (! $this->assurance->governs($request) || $this->assurance->fresh()) {
            return;
        }

        abort_unless($this->assurance->confirms(), 403, $this->assurance->elsewhereMessage());

        throw new HttpResponseException(StepUpPrompt::demand($request, StepUpPlane::Operator, reason: $reason));
    }
}
