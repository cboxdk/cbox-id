<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\EnvironmentMemberPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use Carbon\CarbonImmutable;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ActionApprovals;
use Cbox\Id\OAuthServer\Enums\ActionApprovalStatus;
use Cbox\Id\OAuthServer\ValueObjects\ActionApprovalRequest as ApprovalRequest;
use Closure;

/**
 * Holds an action until a person approves it, when the credential running it says so.
 *
 * The credential's {@see StepUpPolicy} decides whether this action needs approval. If it
 * does, the first attempt files an approval with the person behind the credential — on
 * their device, through the framework's CIBA store where that person is a subject: the
 * platform root for a key's owner, their own environment for a signed-in person — and is answered
 * {@see ApprovalRequired}. The caller polls, then repeats the request naming the approval;
 * this gate spends it only if it was approved, has not lapsed, has not been spent, and was
 * approved for exactly this request: the digest covers the action, every input and the
 * credential, so an approval for one change can never run another.
 *
 * Fails CLOSED: a credential whose policy requires approval but has no person to approve
 * (a key whose minting chain ends in no one) is refused, never let through.
 */
final readonly class ActionApprovalGate
{
    public function __construct(
        private ActionApprovals $approvals,
        private StepUpClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApprovalRequired
     * @throws ActionRefused
     */
    public function enforce(Principal $principal, ActionDefinition $action, array $input, ?string $approvalId): void
    {
        $policy = $principal->stepUpPolicy();

        if ($policy === null || ! $policy->requires($action)) {
            return;
        }

        $digest = $this->digest($principal, $action, $input);

        if ($approvalId !== null && $approvalId !== '') {
            $this->spend($principal, $approvalId, $digest);

            return;
        }

        $approver = $principal->approverSubjectId();

        if ($approver === null) {
            throw new ActionRefused('approval_unavailable', 'This credential needs a person\'s approval for this action, and no person is on record to give it. Run it from the console, or mint a key from the console so its owner can approve.', 403);
        }

        // A short code the person sees beside Approve and the caller shows its user, so the
        // two can be matched by eye — the CIBA binding-message convention.
        $code = strtoupper(bin2hex(random_bytes(2)));
        $message = mb_substr($principal->label().' wants to run '.$action->name, 0, 240).' · '.$code;

        $request = $this->inApproverRealm($principal, fn (): ApprovalRequest => $this->approvals->request(
            $this->client->ensure(),
            $approver,
            $message,
            $digest,
        ));

        // The root is where the approving person lives; a deployment without one has
        // nobody to ask, which is a refusal rather than a pass.
        if ($request === null) {
            throw new ActionRefused('approval_unavailable', 'This deployment has no platform root to file the approval in.', 403);
        }

        // What it is for, so the approver can read it in the console as well as on their
        // phone: the environment whose Approvals page lists it, the code, and the input with
        // its secrets taken out. Reading only — the repeat is what runs, against the digest.
        ActionApprovalRequest::query()->create([
            'id' => $request->requestId,
            'principal' => $principal->kind().':'.$principal->id(),
            'action' => $action->name,
            'environment_id' => match (true) {
                $principal instanceof EnvironmentKeyPrincipal => $principal->key()->environment_id,
                $principal instanceof EnvironmentMemberPrincipal => $principal->environmentId(),
                default => null,
            },
            'binding_code' => $code,
            'input' => ApprovalInput::redact($action, $input),
        ]);

        throw new ApprovalRequired($request->requestId, $code, CarbonImmutable::instance($request->expiresAt), $request->interval);
    }

    /** Where an approval this principal raised stands; null when it is not theirs. */
    public function status(Principal $principal, string $approvalId): ?ActionApprovalStatus
    {
        if (! $this->owns($principal, $approvalId)) {
            return null;
        }

        return $this->inApproverRealm($principal, fn (): ?ActionApprovalStatus => $this->approvals->status($approvalId));
    }

    /** @throws ActionRefused */
    private function spend(Principal $principal, string $approvalId, string $digest): void
    {
        if (! $this->owns($principal, $approvalId)) {
            throw new ActionRefused('approval_invalid', 'No approval with that id belongs to this credential.', 403);
        }

        if ($this->inApproverRealm($principal, fn (): bool => $this->approvals->consume($approvalId, $digest)) === true) {
            return;
        }

        throw match ($this->inApproverRealm($principal, fn (): ?ActionApprovalStatus => $this->approvals->status($approvalId))) {
            ActionApprovalStatus::Pending => new ActionRefused('approval_pending', 'The approval has not been given yet. Poll it, then repeat the request.', 409),
            ActionApprovalStatus::Denied => new ActionRefused('approval_denied', 'The person denied this action.', 403),
            ActionApprovalStatus::Expired => new ActionRefused('approval_expired', 'The approval lapsed before it was used. Repeat the request without it to ask again.', 403),
            default => new ActionRefused('approval_mismatch', 'That approval was already used, or was given for a different request. Repeat the request without it to ask again.', 409),
        };
    }

    /**
     * Run $callback where this principal's approver is a subject — so where the approval is
     * filed, answered on their devices and read back.
     *
     * The platform root for every key and console session: the people who mint keys live
     * there. A person who signed an agent in with a token belongs to the ENVIRONMENT that
     * issued it, and their devices are enrolled there; filing their approval in the root
     * would ask nobody, and a subject id of one environment means nothing in another.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn|null null when there is no such place — a deployment with no platform root
     */
    private function inApproverRealm(Principal $principal, Closure $callback): mixed
    {
        $environmentId = $principal->approverEnvironmentId();

        if ($environmentId === null) {
            return $this->client->platformRoot()->run($callback);
        }

        return app(EnvironmentContext::class)->runAs(GenericEnvironment::of($environmentId), $callback);
    }

    /**
     * Whether $approvalId was raised by this principal. A person signed in at the platform
     * root also owns the approvals they raised while bound to one of their environments
     * ({@see RootPersonPrincipal::owns()}) — `approval_status` and the poll are asked
     * unbound, and must find them.
     */
    private function owns(Principal $principal, string $approvalId): bool
    {
        $owner = ActionApprovalRequest::query()->whereKey($approvalId)->value('principal');

        if (! is_string($owner)) {
            return false;
        }

        return $principal instanceof RootPersonPrincipal
            ? $principal->owns($owner)
            : hash_equals($principal->kind().':'.$principal->id(), $owner);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function digest(Principal $principal, ActionDefinition $action, array $input): string
    {
        return hash('sha256', json_encode([
            $principal->kind().':'.$principal->id(),
            $action->name,
            self::canonical($input),
        ], JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
