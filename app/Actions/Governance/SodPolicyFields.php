<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Governance\Models\SodPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Role-conflict (segregation of duties) rules as the management API returns them, and the
 * two ways one is reached. A helper, not an action.
 *
 * AN ENVIRONMENT-WIDE RULE IS NOT A TENANT'S TO SWITCH OFF. It binds every organization,
 * and an organization administrator who could deactivate it could then grant themselves
 * the very pair it forbids. So such a rule is VISIBLE to an organization (it must know
 * what constrains it) and never CHANGEABLE by one: a change from the organization console
 * resolves only that organization's own rules, and anything else is a 404.
 */
final class SodPolicyFields
{
    /**
     * The rules a principal may READ: narrowed to an organization, its own plus the
     * environment-wide ones that bind it.
     *
     * @return Builder<SodPolicy>
     */
    public static function visible(?string $organizationId): Builder
    {
        return SodPolicy::query()->when($organizationId !== null, fn (Builder $query): Builder => $query->where(
            fn (Builder $scoped): Builder => $scoped->whereNull('organization_id')->orWhere('organization_id', $organizationId),
        ));
    }

    /** @throws ActionRefused */
    public static function readable(ActionContext $context): SodPolicy
    {
        return self::visible(EnterpriseReach::narrowedTo($context))->whereKey($context->string('id'))->first()
            ?? throw ActionRefused::notFound('rule');
    }

    /**
     * The rule, if this principal may CHANGE it: anything in the environment for its own
     * authority, only its own organization's for an organization administrator.
     *
     * @throws ActionRefused
     */
    public static function changeable(ActionContext $context): SodPolicy
    {
        $confinedTo = EnterpriseReach::confinedTo($context);
        $narrowedTo = EnterpriseReach::narrowedTo($context);

        $query = $confinedTo === null
            ? self::visible($narrowedTo)
            : SodPolicy::query()->where('organization_id', $confinedTo);

        return $query->whereKey($context->string('id'))->first() ?? throw ActionRefused::notFound('rule');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(SodPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'organization_id' => $policy->organization_id,
            'name' => $policy->name,
            'description' => $policy->description,
            'role_ids' => array_values(array_filter($policy->role_ids, 'is_string')),
            'active' => (bool) $policy->active,
            'created_at' => Timestamp::of($policy->getAttribute('created_at')),
        ];
    }
}
