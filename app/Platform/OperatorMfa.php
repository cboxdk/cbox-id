<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Operators\OperatorAssurance;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\MfaVerification;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\DatabaseMfaMandate;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\OAuthServer\Enums\AuthenticationContextClass;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;

/** Platform authority always requires MFA, independently of a customer's policy. */
final readonly class OperatorMfa implements MfaMandate, MfaVerification
{
    public function __construct(
        private DatabaseMfaMandate $inner,
        private PlatformRoot $root,
        private PlatformOperators $operators,
        private Mfa $mfa,
        private SessionManager $sessions,
    ) {}

    public function isOperator(string $subjectId): bool
    {
        $request = request();
        $key = 'operator_mfa.'.$subjectId;
        $cached = $request->attributes->get($key);
        if (is_bool($cached)) {
            return $cached;
        }
        $operator = $this->root->model() !== null && $this->root->run(
            fn (): bool => $this->operators->findBySubject($subjectId) !== null,
        );
        $request->attributes->set($key, $operator);

        return $operator;
    }

    public function requiresEnrolment(string $subjectId, ?string $organizationId = null): bool
    {
        if (! $this->isOperator($subjectId)) {
            return $this->inner->requiresEnrolment($subjectId, $organizationId);
        }

        if ($this->requiresPasskey($subjectId)) {
            return true;
        }

        return $this->root->run(fn (): bool => ! $this->mfa->hasConfirmedTotp($subjectId)
            && ! WebAuthnCredential::query()->where('user_id', $subjectId)->exists()) !== false;
    }

    /**
     * Whether an operator must enrol a PASSKEY now: the operator rule is on
     * (`cbox-id.operators.phishing_resistant`), they hold none, and an authenticator app
     * alone no longer qualifies.
     *
     * NOT WHILE A FACTOR THEY HOLD IS STILL OWED. Every door reads "enrolment is required"
     * as "there is nothing to verify with yet" and lets a password-only session through to
     * the enrolment page. An operator who has an authenticator app but no passkey would
     * then add their passkey on the strength of a password alone. So this answers true
     * only once the session in hand has passed the factor they do have (or they have
     * none): the code is asked for first, and the passkey is enrolled from a session that
     * already cleared it.
     */
    public function requiresPasskey(string $subjectId): bool
    {
        // Asked of the container, never held: the rule is scoped to the request and this
        // mandate is a singleton.
        $assurance = app(OperatorAssurance::class);

        if (! $assurance->enabled() || ! $this->isOperator($subjectId) || $assurance->hasPasskey($subjectId)) {
            return false;
        }

        if ($this->root->run(fn (): bool => $this->mfa->hasConfirmedTotp($subjectId)) !== true) {
            return true;
        }

        $session = session()->get(PlatformAuth::SESSION_KEY);
        $held = is_string($session) && $session !== ''
            ? $this->root->run(fn (): ?Session => $this->sessions->active($session))
            : null;

        return $held instanceof Session
            && $held->user_id === $subjectId
            && AuthenticationContextClass::Aal2->isSatisfiedBy(array_values(array_filter((array) $held->amr, is_string(...))));
    }

    public function offersEnrolment(string $subjectId, ?string $organizationId = null): bool
    {
        return $this->isOperator($subjectId) || $this->inner->offersEnrolment($subjectId, $organizationId);
    }

    public function requiresVerification(string $subjectId, array $amr, ?string $organizationId = null): bool
    {
        // NO RECORDED METHODS is not a weak sign-in. A CIBA approval and a device grant
        // carry no `amr`: the person answered them from a surface that already passed
        // this rule (the Authenticator app on their enrolled device, or a signed-in
        // session), and that answer IS the second factor. Demanding one here refused
        // every approval an operator gave ("a second factor is required") once the
        // operator and the app account became the same person. The framework's own rule
        // decides for them, as it does for everybody else.
        if ($amr === []) {
            return $this->inner->requiresVerification($subjectId, $amr, $organizationId);
        }

        return $this->isOperator($subjectId)
            ? ! AuthenticationContextClass::Aal2->isSatisfiedBy($amr)
            : $this->inner->requiresVerification($subjectId, $amr, $organizationId);
    }
}
