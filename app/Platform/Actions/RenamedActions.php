<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Approvals\ActionApprovalGate;

/**
 * The action names v3 retired, and what each is called now.
 *
 * A name is stored where an action is asked for: a pending approval
 * (`action_approval_requests.action`), an idempotent answer (`action_idempotency_records`)
 * and the digest an approval was given for ({@see ActionApprovalGate}). The same goes for a
 * principal's kind in the `kind:id` owner of those rows and in a key's provenance.
 *
 * EXPAND, THEN CONTRACT. ID is live and the release before v3 keeps serving while v3 rolls
 * out — and comes back if the rollout fails — on the same database. So v3.0 still WRITES the
 * v2 spelling ({@see self::stored()}, {@see self::storedKind()}) and READS both; nothing
 * stored is rewritten. v3.1, once v2 can no longer come back, rewrites the rows and writes
 * the new names. {@see ActionRegistry::named()} reads an old name too, so a caller that still
 * says `workspace.get` reaches `organization.get`.
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

    /** The current kind for $kind, as stored (`workspace_key` reads as `organization_key`). */
    public static function currentKind(string $kind): string
    {
        return self::PRINCIPAL_KINDS[$kind] ?? $kind;
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

    /**
     * The name v3.0 writes for the action now called $name: its v2 name when it had one, so
     * the release still running reads what v3 stores. Becomes $name itself in v3.1.
     */
    public static function stored(string $name): string
    {
        return self::retired($name)[0] ?? $name;
    }

    /** The kind v3.0 writes for a principal of $kind: v2's, like {@see self::stored()}. */
    public static function storedKind(string $kind): string
    {
        return self::retiredKinds($kind)[0] ?? $kind;
    }

    /**
     * Every spelling of the action now called $name: the current one first.
     *
     * @return list<string>
     */
    public static function spellings(string $name): array
    {
        return array_values(array_unique([$name, ...self::retired($name)]));
    }

    /**
     * Every spelling of the principal kind $kind: the current one first.
     *
     * @return list<string>
     */
    public static function kindSpellings(string $kind): array
    {
        return array_values(array_unique([$kind, ...self::retiredKinds($kind)]));
    }
}
