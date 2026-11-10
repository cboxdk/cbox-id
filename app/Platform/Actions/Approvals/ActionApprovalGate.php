<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\EnvironmentMemberPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\Actions\RenamedActions;
use App\Platform\Agents\ApprovalMail;
use App\Platform\EnvironmentOrigin;
use App\Platform\PlaneResolver;
use Carbon\CarbonImmutable;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ActionApprovals;
use Cbox\Id\OAuthServer\Enums\ActionApprovalStatus;
use Cbox\Id\OAuthServer\ValueObjects\ActionApprovalRequest as ApprovalRequest;
use Closure;
use Throwable;

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
    /** The framework's floor and ceiling on an approval's life ({@see ActionApprovals::request()}). */
    private const int MIN_TTL_SECONDS = 30;

    private const int MAX_TTL_SECONDS = 900;

    /** The framework's default, when `CBOX_ID_CIBA_TTL_SECONDS` says nothing usable. */
    private const int DEFAULT_TTL_SECONDS = 300;

    /** The framework's ceiling on a binding message ({@see ActionApprovals::request()}). */
    private const int BINDING_MESSAGE_MAX = 255;

    public function __construct(
        private ActionApprovals $approvals,
        private StepUpClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return string|null The approval this run spent, or null when it needed none.
     *
     * @throws ApprovalRequired
     * @throws ActionRefused
     */
    public function enforce(Principal $principal, ActionDefinition $action, array $input, ?string $approvalId): ?string
    {
        $policy = $principal->stepUpPolicy();

        if ($policy === null || ! $policy->requires($action)) {
            return null;
        }

        $digest = $this->digest($principal, $action, $input);

        if ($approvalId !== null && $approvalId !== '') {
            $this->spend($principal, $approvalId, $digest, $action, $input);

            return $approvalId;
        }

        $approver = $principal->approverSubjectId();

        if ($approver === null) {
            throw new ActionRefused('approval_unavailable', new ActionMessage('console.this_credential_needs_a_person_s_approval_for_this_acti_6e7eed81'), 403);
        }

        // A short code the person sees beside Approve and the caller shows its user, so the
        // two can be matched by eye — the CIBA binding-message convention.
        $code = strtoupper(bin2hex(random_bytes(2)));
        // The framework holds a binding message to 255 characters. What is cut, when anything
        // is, is the principal's label — never the action, nor the code the two sides compare.
        $suffix = ' wants to run '.$action->name.' · '.$code;
        $message = mb_substr($principal->label(), 0, self::BINDING_MESSAGE_MAX - mb_strlen($suffix)).$suffix;

        $request = $this->inApproverRealm($principal, fn (): ApprovalRequest => $this->approvals->request(
            $this->client->ensure(),
            $approver,
            $message,
            $digest,
            self::ttlSeconds(),
        ));

        // The root is where the approving person lives; a deployment without one has
        // nobody to ask, which is a refusal rather than a pass.
        if ($request === null) {
            throw new ActionRefused('approval_unavailable', new ActionMessage('console.this_deployment_has_no_platform_root_to_file_the_approv_1fcb515e'), 403);
        }

        // What it is for, so the approver can read it in the console as well as on their
        // phone: the environment whose Approvals page lists it, the code, and the input with
        // its secrets taken out. Reading only — the repeat is what runs, against the digest.
        ActionApprovalRequest::query()->create([
            'id' => $request->requestId,
            // v2's spellings while v2 may still serve this database ({@see RenamedActions}).
            'principal' => RenamedActions::storedKind($principal->kind()).':'.$principal->id(),
            'action' => RenamedActions::stored($action->name),
            'environment_id' => match (true) {
                $principal instanceof EnvironmentKeyPrincipal => $principal->key()->environment_id,
                $principal instanceof EnvironmentMemberPrincipal => $principal->environmentId(),
                default => null,
            },
            'binding_code' => $code,
            'input' => ApprovalInput::redact($action, $input),
        ]);

        $this->mail($principal, $approver, $request);

        throw new ApprovalRequired($request->requestId, $code, CarbonImmutable::instance($request->expiresAt), $request->interval);
    }

    /**
     * Mail the approver when no phone will tell them ({@see ApprovalMail}), in their realm and
     * with a link to the Approvals page on the host that serves it: the account console for a
     * person of the platform root, the environment's own host for one of its people. That page
     * lists the held action beside any sign-in request and answers the same CIBA request the
     * phone would. Fails open — the action is held either way.
     */
    private function mail(Principal $principal, string $approver, ApprovalRequest $request): void
    {
        try {
            $environmentId = $principal->approverEnvironmentId();

            $url = $environmentId === null
                ? self::onConsoleHost(route('approvals', [], false))
                : app(EnvironmentOrigin::class)->run($environmentId, static fn (): string => route('approvals'));

            $this->inApproverRealm($principal, fn () => app(ApprovalMail::class)->notify(
                subjectId: $approver,
                app: $principal->label(),
                url: $url,
                expiresAt: $request->expiresAt,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function onConsoleHost(string $path): string
    {
        $host = app(PlaneResolver::class)->consoleHost();

        return $host === null ? url($path) : 'https://'.$host.$path;
    }

    /**
     * How long a held action waits for its person, in seconds — passed to every request
     * this gate files, and read by the pages that tell people how long they have.
     *
     * Said HERE rather than left to the framework's own default, because a page that told
     * people the wait was "fifteen minutes" while the requests lapsed after five was the
     * result of two places each holding a number. Now the page reads the one the gate
     * sends: `cbox-id.oauth.ciba.ttl_seconds` (`CBOX_ID_CIBA_TTL_SECONDS`, five minutes by
     * default), clamped to the framework's own bounds so the number shown is the number
     * kept.
     */
    public static function ttlSeconds(): int
    {
        $configured = config('cbox-id.oauth.ciba.ttl_seconds', self::DEFAULT_TTL_SECONDS);
        $seconds = is_numeric($configured) && (int) $configured > 0 ? (int) $configured : self::DEFAULT_TTL_SECONDS;

        return max(self::MIN_TTL_SECONDS, min($seconds, self::MAX_TTL_SECONDS));
    }

    /** The same window, in words for a sentence: "5 minutes", "90 seconds". */
    public static function window(): string
    {
        $seconds = self::ttlSeconds();

        if ($seconds % 60 !== 0) {
            return $seconds.' seconds';
        }

        $minutes = intdiv($seconds, 60);

        return $minutes === 1 ? '1 minute' : $minutes.' minutes';
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
    /**
     * @param  array<string, mixed>  $input
     */
    private function spend(Principal $principal, string $approvalId, string $digest, ActionDefinition $action, array $input): void
    {
        if (! $this->owns($principal, $approvalId)) {
            throw new ActionRefused('approval_invalid', new ActionMessage('console.no_approval_with_that_id_belongs_to_this_credential_5e7c701c'), 403);
        }

        if ($this->inApproverRealm($principal, fn (): bool => $this->approvals->consume($approvalId, $digest)) === true) {
            return;
        }

        // The same request under the other spellings of the action and the key kind — an
        // approval raised under the v3 names, or one a later release raises under them.
        foreach ($this->otherDigests($principal, $action, $input, $digest) as $retired) {
            if ($this->inApproverRealm($principal, fn (): bool => $this->approvals->consume($approvalId, $retired)) === true) {
                return;
            }
        }

        throw match ($this->inApproverRealm($principal, fn (): ?ActionApprovalStatus => $this->approvals->status($approvalId))) {
            ActionApprovalStatus::Pending => new ActionRefused('approval_pending', new ActionMessage('console.the_approval_has_not_been_given_yet_poll_it_then_repeat_eb93af18'), 409),
            ActionApprovalStatus::Denied => new ActionRefused('approval_denied', new ActionMessage('console.the_person_denied_this_action_4670428a'), 403),
            ActionApprovalStatus::Expired => new ActionRefused('approval_expired', new ActionMessage('console.the_approval_lapsed_before_it_was_used_repeat_the_reque_209719bd'), 403),
            default => new ActionRefused('approval_mismatch', new ActionMessage('console.that_approval_was_already_used_or_was_given_for_a_diffe_86a561c7'), 409),
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

        if ($principal instanceof RootPersonPrincipal) {
            return $principal->owns($owner);
        }

        foreach (RenamedActions::kindSpellings($principal->kind()) as $kind) {
            if (hash_equals($kind.':'.$principal->id(), $owner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The digests this request has under every other spelling of its action and its kind
     * ({@see RenamedActions}): the one it is asked for is $digest.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function otherDigests(Principal $principal, ActionDefinition $action, array $input, string $digest): array
    {
        $digests = [];

        foreach (RenamedActions::kindSpellings($principal->kind()) as $kind) {
            foreach (RenamedActions::spellings($action->name) as $name) {
                $other = self::hashed($kind.':'.$principal->id(), $name, $input);

                if ($other !== $digest) {
                    $digests[] = $other;
                }
            }
        }

        return $digests;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function digest(Principal $principal, ActionDefinition $action, array $input): string
    {
        // Taken under the names v2 reads, so an approval v3 raises can still be spent by v2
        // during the rollout ({@see RenamedActions::stored()}).
        return self::hashed(RenamedActions::storedKind($principal->kind()).':'.$principal->id(), RenamedActions::stored($action->name), $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function hashed(string $owner, string $name, array $input): string
    {
        return hash('sha256', json_encode([$owner, $name, self::canonical($input)], JSON_THROW_ON_ERROR));
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
