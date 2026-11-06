<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\MfaVerification;
use Cbox\Id\Identity\DatabaseMfaMandate;
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

        return $this->root->run(fn (): bool => ! $this->mfa->hasConfirmedTotp($subjectId)
            && ! WebAuthnCredential::query()->where('user_id', $subjectId)->exists()) !== false;
    }

    public function offersEnrolment(string $subjectId, ?string $organizationId = null): bool
    {
        return $this->isOperator($subjectId) || $this->inner->offersEnrolment($subjectId, $organizationId);
    }

    public function requiresVerification(string $subjectId, array $amr, ?string $organizationId = null): bool
    {
        return $this->isOperator($subjectId)
            ? ! AuthenticationContextClass::Aal2->isSatisfiedBy($amr)
            : $this->inner->requiresVerification($subjectId, $amr, $organizationId);
    }
}
