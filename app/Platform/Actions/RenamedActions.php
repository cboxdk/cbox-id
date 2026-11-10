<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Approvals\ActionApprovalGate;

/**
 * The action names v3 retired, and what each is called now.
 *
 * A name is stored where an action was asked for before the rename: a pending approval
 * (`action_approval_requests.action`), an idempotent answer (`action_idempotency_records`)
 * and the digest an approval was given for ({@see ActionApprovalGate}). The rename
 * migration rewrites the two tables; the digest is a hash nobody can rewrite, so the gate
 * also accepts one taken under the old name. {@see ActionRegistry::named()} reads an old
 * name too, so a caller that still says `workspace.get` reaches `organization.get`.
 *
 * The hash-chained audit trails keep the old names — they are history — and are translated
 * when read.
 */
final class RenamedActions
{
    /** @var array<string, string> old name => new name */
    public const array MAP = [
        'workspace.get' => 'organization.get',
        'workspace.settings.update' => 'organization.settings.update',
        'keys.workspace.list' => 'keys.organization.list',
        'keys.workspace.create' => 'keys.organization.create',
        'keys.workspace.revoke' => 'keys.organization.revoke',
        'platform.workspaces.create' => 'admin.organizations.create',
        'platform.workspaces.set_status' => 'admin.organizations.set_status',
        'platform.organizations.create' => 'admin.tenant_organizations.create',
        'platform.organizations.set_status' => 'admin.tenant_organizations.set_status',
        'platform.organizations.move' => 'admin.tenant_organizations.move',
        'platform.operators.create' => 'admin.operators.create',
        'platform.operators.set_status' => 'admin.operators.set_status',
        'platform.environments.create' => 'admin.environments.create',
        'platform.environments.provision' => 'admin.environments.provision',
    ];

    /** @var array<string, string> old principal kind => new kind, as stored in `kind:id` owners */
    public const array PRINCIPAL_KINDS = [
        'workspace_key' => 'organization_key',
    ];

    /** The current name for $name: itself unless it was retired. */
    public static function current(string $name): string
    {
        return self::MAP[$name] ?? $name;
    }

    /**
     * The retired names of the action now called $name.
     *
     * @return list<string>
     */
    public static function retired(string $name): array
    {
        return array_keys(array_filter(self::MAP, static fn (string $new): bool => $new === $name));
    }

    /**
     * The retired kinds a principal of $kind was stored under.
     *
     * @return list<string>
     */
    public static function retiredKinds(string $kind): array
    {
        return array_keys(array_filter(self::PRINCIPAL_KINDS, static fn (string $new): bool => $new === $kind));
    }
}
