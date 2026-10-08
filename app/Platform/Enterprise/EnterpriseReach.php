<?php

declare(strict_types=1);

namespace App\Platform\Enterprise;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\Entitlements;
use App\Platform\Integrations\IntegrationReach;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * WHOSE enterprise plumbing an action may reach — SSO connections and their domains,
 * directories, outbound targets, role-conflict rules, access reviews and the token vault —
 * asked once for every door.
 *
 * Two principals reach these actions, as they reach the integrations
 * ({@see IntegrationReach}):
 *
 *  - an environment administrator, or a management key: the ENVIRONMENT's authority, over
 *    every organization in it. An `organization_id` they send narrows what they look at,
 *    the way the environment console's organization picker does — it never widens it.
 *  - an organization administrator on the organization console: their organization and
 *    nothing else. The console always sends it, and {@see OrganizationTarget::check()}
 *    refuses any other (or none) as the forged request it would be.
 *
 * A record outside what the caller may reach is a 404 rather than a 403 — the caller was
 * not entitled to learn it exists — and every lookup puts the owner IN THE QUERY rather
 * than in an `if` after it, the shape that once shipped a cross-organization IDOR here.
 */
final class EnterpriseReach
{
    /**
     * The organization the caller narrowed this request to, checked: null for "the whole
     * environment", which only the environment's own authority can mean.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function narrowedTo(ActionContext $context): ?string
    {
        return OrganizationTarget::check($context, $context->nullableString('organization_id'));
    }

    /** The organization-console administrator's own organization, or null for the environment's authority. */
    public static function confinedTo(ActionContext $context): ?string
    {
        return IntegrationReach::confinedTo($context->principal);
    }

    /**
     * The optional `organization_id` filter every lookup and list here takes — a list's
     * "only this organization's", a lookup's "only if it is this organization's".
     */
    public static function narrowField(bool $list = false): Field
    {
        return Field::string('organization_id')->nullable()->max(64)->describe($list
            ? 'Only this organization\'s.'
            : 'Only if it is this organization\'s; anything else is a 404.');
    }

    /**
     * The two inputs {@see self::owner()} reads.
     *
     * @return list<Field>
     */
    public static function ownerFields(string $what): array
    {
        return [
            Field::string('organization_id')->nullable()->max(64)->describe("The organization {$what} belongs to. Send this or environment_wide."),
            Field::boolean('environment_wide')->describe("True to make {$what} the environment's own rather than one organization's. Send this or organization_id."),
        ];
    }

    /**
     * Who a NEW record belongs to: `organization_id`, or the environment itself when
     * `environment_wide` is true — exactly one, said out loud. A null that arrives from a
     * forgotten field is indistinguishable from one that was meant, and the environment's
     * own record reaches every organization's people.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function owner(ActionContext $context, string $what): ?string
    {
        $organizationId = $context->nullableString('organization_id');

        if ($context->boolean('environment_wide')) {
            if ($organizationId !== null) {
                throw ActionRefused::because('ambiguous_owner', 'Send organization_id or environment_wide, not both.', 'organization_id');
            }

            if (self::confinedTo($context) !== null) {
                throw new ActionRefused('forbidden', "Only an environment administrator may create {$what} for the whole environment.", 403);
            }

            return null;
        }

        if ($organizationId === null) {
            throw ActionRefused::because('owner_required', "Send organization_id, or environment_wide: true to make {$what} the environment's own.", 'organization_id');
        }

        return OrganizationTarget::check($context, $organizationId);
    }

    /**
     * An organization that is required — an email domain, a directory, a portal link
     * belongs to exactly one.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function requiredOrganization(ActionContext $context, bool $inPath = false): string
    {
        return (string) OrganizationTarget::check($context, $context->string('organization_id'), $inPath);
    }

    /**
     * Refuse unless $organizationId's plan includes $feature. The ENVIRONMENT's own record
     * (null) has no plan to ask — it is the environment's own capability.
     *
     * @throws ActionRefused
     */
    public static function assertEntitled(?string $organizationId, string $feature): void
    {
        if ($organizationId !== null && ! app(Entitlements::class)->entitled($organizationId, $feature)) {
            throw new ActionRefused('not_entitled', 'This organization\'s plan does not include '.self::featureName($feature).'.', 403);
        }
    }

    private static function featureName(string $feature): string
    {
        return match ($feature) {
            'sso' => 'single sign-on',
            'scim' => 'directory sync',
            'audit_logs' => 'audit logs',
            default => $feature,
        };
    }
}
