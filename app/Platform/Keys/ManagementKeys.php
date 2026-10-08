<?php

declare(strict_types=1);

namespace App\Platform\Keys;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\EnvironmentMemberPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\OrganizationActivity;
use Carbon\CarbonImmutable;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Exceptions\UnknownApiKeyScope;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\IssuedEnvironmentApiKey;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;

/**
 * Minting, rotating and revoking an environment's management keys — by a person in the
 * console or by another key.
 *
 * A KEY MAY MINT KEYS, BUT NEVER A WIDER ONE. A key-minted key carries at most its parent's
 * scopes, expires no later than its parent and never later than {@see maxTtlDays()} from
 * now, and records its parent — so revoking the parent revokes everything it made, and an
 * agent handed a narrow key cannot climb out of it by minting itself a broader one.
 *
 * Every change is recorded on the trail of the WORKSPACE that owns the environment, which
 * is where the console has always recorded a key: a key is the workspace's credential, not
 * the act of one of the environment's customers.
 */
final readonly class ManagementKeys
{
    public function __construct(
        private EnvironmentApiKeys $keys,
        private OrganizationActivity $activity,
        private PlatformRoot $platformRoot,
    ) {}

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>|null  $stepUpPolicy
     *
     * @throws ActionRefused
     */
    public function mint(
        Principal $principal,
        string $environmentId,
        string $name,
        array $scopes,
        ?CarbonImmutable $expiresAt,
        ?string $description = null,
        ?array $stepUpPolicy = null,
        ?string $rotatedFromId = null,
    ): IssuedEnvironmentApiKey {
        $scopes = array_values(array_unique($scopes));

        if ($scopes === []) {
            throw ActionRefused::because('invalid_scopes', 'A key needs at least one scope; one with none can do nothing.', 'scopes');
        }

        $parent = $principal instanceof EnvironmentKeyPrincipal ? $principal->key() : null;

        if ($parent !== null) {
            $wider = array_values(array_diff($scopes, $parent->scopes));

            if ($wider !== []) {
                throw ActionRefused::because('scope_exceeds_parent', 'A key can only mint keys within its own scopes. Not held by this key: '.implode(', ', $wider).'.', 'scopes');
            }

            $expiresAt = $this->boundedExpiry($expiresAt, $parent);

            // Never weaker supervision than the key doing the minting: an agent cannot shed
            // the approvals its owner required by minting itself a fresh key.
            $stepUpPolicy = StepUpPolicy::strictest(
                StepUpPolicy::fromArray($parent->step_up_policy),
                StepUpPolicy::fromArray($stepUpPolicy),
            )?->toArray();
        }

        try {
            $issued = $this->keys->issue($environmentId, $name, $scopes, $expiresAt, new KeyProvenance(
                createdByType: $this->creatorType($principal),
                createdById: $this->creatorId($principal),
                parentKeyId: $parent?->id,
                rotatedFromId: $rotatedFromId,
                description: $description,
                stepUpPolicy: $stepUpPolicy,
            ));
        } catch (UnknownApiKeyScope $refused) {
            throw ActionRefused::because('invalid_scopes', $refused->getMessage(), 'scopes');
        }

        $this->record($principal, $environmentId, 'organization.environment_key_created', [
            'key_id' => $issued->key->id,
            'name' => $name,
            'scopes' => $scopes,
            'expires_at' => $issued->key->expires_at?->toIso8601String(),
            'parent_key_id' => $parent?->id,
            'rotated_from_id' => $rotatedFromId,
        ]);

        return $issued;
    }

    /**
     * Mint a successor with the same name, scopes, description and policy, and let the old
     * key live on for `$graceHours` so whatever uses it can switch without an outage.
     *
     * @throws ActionRefused
     */
    public function rotate(Principal $principal, EnvironmentApiKey $old, int $graceHours): IssuedEnvironmentApiKey
    {
        if (! $old->isActive()) {
            throw ActionRefused::because('key_inactive', 'Only an active key can be rotated. Mint a new one instead.');
        }

        $successor = $this->mint(
            $principal,
            $old->environment_id,
            $old->name,
            $old->scopes,
            $old->expires_at === null ? null : CarbonImmutable::instance($old->expires_at),
            $old->description,
            $old->step_up_policy,
            rotatedFromId: $old->id,
        );

        $retireAt = CarbonImmutable::now()->addHours(max(0, $graceHours));

        if ($old->expires_at === null || $old->expires_at->greaterThan($retireAt)) {
            $old->forceFill(['expires_at' => $retireAt])->save();
        }

        return $successor;
    }

    /**
     * Revoke a key and every key it minted, all the way down. Returns the ids revoked now —
     * an already-revoked key in the tree is passed over, not recorded again.
     *
     * @return list<string>
     */
    public function revoke(Principal $principal, EnvironmentApiKey $key): array
    {
        $revoked = [];
        $queue = [$key];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current->revoked_at === null) {
                $this->keys->revoke($current->environment_id, $current->id);
                $revoked[] = $current->id;

                $this->record($principal, $current->environment_id, 'organization.environment_key_revoked', [
                    'key_id' => $current->id,
                    'name' => $current->name,
                    'because_parent_revoked' => $current->id !== $key->id,
                ]);
            }

            foreach (EnvironmentApiKey::query()->where('parent_key_id', $current->id)->get() as $child) {
                $queue[] = $child;
            }
        }

        return $revoked;
    }

    /** The most days a key-minted key may live (`api.minted_key_max_ttl_days`, default 90). */
    public static function maxTtlDays(): int
    {
        $configured = config('api.minted_key_max_ttl_days', 90);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 90;
    }

    /** @throws ActionRefused */
    private function boundedExpiry(?CarbonImmutable $requested, EnvironmentApiKey $parent): CarbonImmutable
    {
        $ceiling = CarbonImmutable::now()->addDays(self::maxTtlDays());

        if ($parent->expires_at !== null && $parent->expires_at->lessThan($ceiling)) {
            $ceiling = CarbonImmutable::instance($parent->expires_at);
        }

        if ($requested === null) {
            return $ceiling;
        }

        if ($requested->greaterThan($ceiling)) {
            throw ActionRefused::because('expiry_exceeds_parent', 'A key-minted key must expire by '.$ceiling->toIso8601String().': no later than the key minting it, and at most '.self::maxTtlDays().' days from now.', 'expires_at');
        }

        return $requested;
    }

    private function creatorType(Principal $principal): string
    {
        return match (true) {
            $principal instanceof EnvironmentKeyPrincipal => 'environment_key',
            $principal instanceof ConsoleSessionPrincipal, $principal instanceof EnvironmentMemberPrincipal => 'organization_member',
            default => $principal->kind(),
        };
    }

    /**
     * Who the key records as its maker. A workspace member acting through a token is
     * recorded as the PERSON, exactly as from their console — so the key's held actions go
     * to them for approval — never as the person-and-client id the token's principal keys
     * its idempotency on.
     */
    private function creatorId(Principal $principal): ?string
    {
        $id = $principal instanceof EnvironmentMemberPrincipal ? $principal->subjectId() : $principal->id();

        return $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(Principal $principal, string $environmentId, string $action, array $context): void
    {
        $workspaceId = $this->workspaceOf($environmentId);

        if ($workspaceId === null) {
            return;
        }

        $actor = $principal->auditActor();

        $this->activity->record(
            $workspaceId,
            $action,
            $actor->id,
            targetType: 'environment',
            targetId: $environmentId,
            context: $context,
            actorType: $actor->type === ActorType::Service ? ActorType::Service : ActorType::OrganizationMember,
        );
    }

    /** The workspace (organization in the platform root) whose project the environment is in. */
    private function workspaceOf(string $environmentId): ?string
    {
        return $this->platformRoot->run(function () use ($environmentId): ?string {
            $projectId = Environment::query()->whereKey($environmentId)->value('project_id');

            if (! is_string($projectId)) {
                return null;
            }

            $workspaceId = Project::query()->whereKey($projectId)->value('organization_id');

            return is_string($workspaceId) ? $workspaceId : null;
        });
    }
}
