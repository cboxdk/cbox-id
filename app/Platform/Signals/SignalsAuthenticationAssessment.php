<?php

declare(strict_types=1);

namespace App\Platform\Signals;

use App\Platform\Radar\RadarDevices;
use Cbox\Id\Identity\Contracts\AuthenticationAssessment;
use Cbox\Id\Identity\ValueObjects\VerifiedAuthentication;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Enums\AuthenticationContextClass;
use Cbox\Signals\Contracts\Signals;
use Cbox\Signals\Data\Entity;
use Cbox\Signals\Data\EvaluationRequest;
use Cbox\Signals\Data\EvaluationStatus;
use Cbox\Signals\Exceptions\SignalsFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Signals supplies evidence; this host retains the authentication decision. */
final readonly class SignalsAuthenticationAssessment implements AuthenticationAssessment
{
    public function __construct(
        private Signals $signals,
        private EnvironmentContext $environments,
        private RadarDevices $devices,
        private Request $request,
        private AuditLog $audit,
        private string $environment,
        private int $freshSeconds = 300,
        private string $entityNamespace = 'cbox-id',
    ) {}

    public function assertAllowed(VerifiedAuthentication $authentication): void
    {
        // A deployment key belongs to exactly one ID environment. Never send another
        // tenant's subject to that project's Signals API, even if IDs happen to match.
        if ($this->environments->current()?->environmentKey() !== $this->environment) {
            return;
        }
        $attempt = (string) Str::uuid();
        $device = $this->devices->identify($this->request)->deviceHash;
        try {
            $evaluation = $this->signals->evaluate(new EvaluationRequest(
                $attempt, new Entity($this->entityNamespace, 'user', $authentication->subjectId), $device,
                $authentication->ip ?? '',
            ));
        } catch (SignalsFailure $failure) {
            // Only the assessment is unavailable. All ordinary ID checks still run.
            // Do not retain remote response text, tokens or request payloads in audit.
            $this->audit->record(AuditEvent::forUser('signals.assessment_unavailable', $authentication->subjectId, $authentication->organizationId, [
                'request_id' => $attempt, 'http_status' => $failure->status,
                'issuance' => $authentication->issuance->value,
            ]));

            return;
        }
        $now = now()->getTimestamp();
        $freshStrong = AuthenticationContextClass::Aal2->isSatisfiedBy($authentication->amr)
            && $authentication->authenticatedAt !== null
            && $authentication->authenticatedAt <= $now
            && $authentication->authenticatedAt >= $now - $this->freshSeconds;
        $signals = [];
        if ($evaluation->status !== EvaluationStatus::Degraded && $evaluation->dataAsOf !== null
            && $evaluation->dataAsOf->getTimestamp() <= $now
            && $evaluation->dataAsOf->getTimestamp() >= $now - 86400) {
            foreach ($evaluation->signals as $signal) {
                $coverage = match ($signal->type) {
                    'identity.unusual_context' => 'identity',
                    'access.resource_probing' => 'access',
                    default => null,
                };
                if ($coverage !== null && ($evaluation->coverage[$coverage] ?? '') === 'ready'
                    && in_array($signal->severity, ['high', 'critical'], true)
                    && $signal->expiresAt->getTimestamp() > $now
                    && $signal->id !== null && $signal->revision !== null && $signal->revision > 0) {
                    $signals[] = ['id' => $signal->id, 'revision' => $signal->revision];
                }
            }
        }
        $challenge = $signals !== [] && ! $freshStrong;
        // Persist both the revisions handled and the actual assurance. A fresh MFA
        // completion passes the same episode without recursively challenging it.
        $this->audit->record(AuditEvent::forUser('signals.authentication_assessed', $authentication->subjectId, $authentication->organizationId, [
            'request_id' => $attempt, 'assessment_id' => $evaluation->id,
            'assessment_status' => $evaluation->status->value,
            'issuance' => $authentication->issuance->value,
            'decision' => $challenge ? 'step_up' : 'continue_id_policy',
            'signals' => $signals, 'assurance' => AuthenticationContextClass::forAmr($authentication->amr)->value,
            'authenticated_at' => $authentication->authenticatedAt,
        ]));
        if ($challenge) {
            throw new SignalsStepUpRequired($evaluation->id, $authentication);
        }
    }
}
