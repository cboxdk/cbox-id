<?php

declare(strict_types=1);

namespace App\Platform\Agents;

use App\Http\Props\Console\AgentRowProps;
use App\Http\Props\Console\KeyLifecycleProps;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Keys\ManagementKeys;
use Carbon\CarbonImmutable;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Closure;

/**
 * An environment's management keys, as the agents that hold them — in the order a person
 * reads a family tree: every key a key minted directly under it, indented.
 *
 * The tree is what makes revoking legible. Revoking a key revokes everything it minted,
 * all the way down ({@see ManagementKeys::revoke()}), and a flat list
 * hid that the agent you were about to cut off had handed three narrower keys to its
 * sub-agents. Each row carries how many live keys go with it.
 */
final readonly class AgentRoster
{
    public function __construct(
        private EnvironmentApiKeys $keys,
        private AgentScopes $scopes,
        private PlatformRoot $platformRoot,
    ) {}

    /**
     * @param  Closure(EnvironmentApiKey): string  $rotateHref
     * @param  Closure(EnvironmentApiKey): string  $revokeHref
     * @return array{rows: list<AgentRowProps>, inactive: int}
     */
    public function for(string $environmentId, bool $includeInactive, Closure $rotateHref, Closure $revokeHref): array
    {
        $now = CarbonImmutable::now();
        $all = array_values($this->keys->forEnvironment($environmentId)->all());

        /** @var array<string, EnvironmentApiKey> $byId */
        $byId = [];
        /** @var array<string, list<EnvironmentApiKey>> $children */
        $children = [];

        foreach ($all as $key) {
            $byId[$key->id] = $key;
        }

        foreach ($all as $key) {
            if ($key->parent_key_id !== null && isset($byId[$key->parent_key_id])) {
                $children[$key->parent_key_id][] = $key;
            }
        }

        $creators = $this->creatorNames($all, $byId);
        $offered = count($this->scopes->catalogue());
        $risks = $this->scopes->risks();

        $rows = [];
        $inactive = 0;

        // Newest first at the top level (the store returns them that way); a key minted by a
        // key directly under its parent, its own children under it.
        $visit = function (EnvironmentApiKey $key, int $depth) use (&$visit, &$rows, &$inactive, $children, $creators, $includeInactive, $now, $offered, $risks, $rotateHref, $revokeHref): void {
            $lifecycle = KeyLifecycleProps::of(KeyLifecycleProps::createdAt($key), $key->last_used_at, $key->expires_at, $key->revoked_at, $now);

            if (! $lifecycle->active()) {
                $inactive++;
            }

            if ($includeInactive || $lifecycle->active()) {
                $scopes = array_values(array_filter($key->scopes, 'is_string'));

                $rows[] = new AgentRowProps(
                    key: $key,
                    scopes: $scopes,
                    scopeSummary: self::summary($scopes, $offered),
                    risk: AgentScopes::highest($scopes, $risks),
                    policy: StepUpPolicy::fromArray($key->step_up_policy),
                    createdBy: $creators[$key->id] ?? 'Unknown',
                    depth: $depth,
                    descendants: $this->liveDescendants($key, $children, $now),
                    lifecycle: $lifecycle,
                    rotateHref: $lifecycle->active() ? $rotateHref($key) : null,
                    revokeHref: $lifecycle->active() ? $revokeHref($key) : null,
                );
            }

            foreach (array_reverse($children[$key->id] ?? []) as $child) {
                $visit($child, $depth + 1);
            }
        };

        foreach ($all as $key) {
            if ($key->parent_key_id === null || ! isset($byId[$key->parent_key_id])) {
                $visit($key, 0);
            }
        }

        return ['rows' => $rows, 'inactive' => $inactive];
    }

    /**
     * "Read-only", "All scopes", "12 scopes" — the shape of what it holds, not the list.
     *
     * @param  list<string>  $scopes
     */
    public static function summary(array $scopes, int $offered): string
    {
        $count = count($scopes);
        $readOnly = array_filter($scopes, static fn (string $scope): bool => ! str_ends_with($scope, ':read')) === [];

        return match (true) {
            $readOnly && $count > 0 => 'Read-only · '.$count.' '.($count === 1 ? 'scope' : 'scopes'),
            $offered > 0 && $count >= $offered => 'All scopes',
            default => $count.' '.($count === 1 ? 'scope' : 'scopes'),
        };
    }

    /**
     * @param  array<string, list<EnvironmentApiKey>>  $children
     */
    private function liveDescendants(EnvironmentApiKey $key, array $children, CarbonImmutable $now): int
    {
        $count = 0;
        $queue = $children[$key->id] ?? [];

        while ($queue !== []) {
            $child = array_shift($queue);

            if ($child->revoked_at === null && ($child->expires_at === null || $child->expires_at->greaterThan($now))) {
                $count++;
            }

            array_push($queue, ...($children[$child->id] ?? []));
        }

        return $count;
    }

    /**
     * Who minted each key, in words: the person (from the platform root, where they are a
     * subject), the key that minted it, or the workspace key that did.
     *
     * Two batched reads at most, never one per row.
     *
     * @param  list<EnvironmentApiKey>  $keys
     * @param  array<string, EnvironmentApiKey>  $byId
     * @return array<string, string>
     */
    private function creatorNames(array $keys, array $byId): array
    {
        $people = [];
        $workspaceKeys = [];

        foreach ($keys as $key) {
            if ($key->created_by_type === 'organization_member' && is_string($key->created_by_id)) {
                $people[] = $key->created_by_id;
            } elseif ($key->created_by_type === 'workspace_key' && is_string($key->created_by_id)) {
                $workspaceKeys[] = $key->created_by_id;
            }
        }

        /** @var array<string, string> $names */
        $names = $people === [] ? [] : ($this->platformRoot->run(fn (): array => User::query()
            ->whereIn('id', array_values(array_unique($people)))
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(static fn (User $user): array => [$user->id => (string) ($user->name ?? $user->email)])
            ->all()) ?? []);

        /** @var array<string, string> $workspace */
        $workspace = $workspaceKeys === [] ? [] : OrganizationApiKey::query()
            ->whereIn('id', array_values(array_unique($workspaceKeys)))
            ->pluck('name', 'id')
            ->map(static fn (mixed $name): string => 'Workspace key "'.(is_string($name) ? $name : '').'"')
            ->all();

        $out = [];

        foreach ($keys as $key) {
            $id = $key->created_by_id;

            $out[$key->id] = match ($key->created_by_type) {
                'organization_member' => is_string($id) ? ($names[$id] ?? 'A former member') : 'Unknown',
                'environment_key' => $key->parent_key_id !== null && isset($byId[$key->parent_key_id])
                    ? 'Key "'.$byId[$key->parent_key_id]->name.'"'
                    : 'Another key',
                'workspace_key' => is_string($id) ? ($workspace[$id] ?? 'A workspace key') : 'A workspace key',
                default => 'Unknown',
            };
        }

        return $out;
    }
}
