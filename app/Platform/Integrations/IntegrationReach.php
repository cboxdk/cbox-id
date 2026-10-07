<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Console\ConsolePlane;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * WHOSE integrations a principal may see, change and create — webhooks, inline hooks and
 * log streams alike, from whichever door.
 *
 * Every one of the three carries a nullable `organization_id`, and null is not "nobody's":
 * it is the ENVIRONMENT's own, which receives — or, for a hook, may refuse — every
 * organization's traffic. Two principals reach these actions:
 *
 *  - an environment administrator on the environment console, or a management key: the
 *    environment's own authority, which sits above every organization in it. Nothing is
 *    narrowed; {@see self::confinedTo()} answers null.
 *  - an organization administrator on the organization console: their organization and
 *    nothing else. They SEE the environment's own webhooks and hooks (each receives their
 *    events, and one they could not see is one they could not ask about) but may change
 *    only their own, and may never mint one that is the environment's.
 *
 * The consoles made each of these decisions in each controller. As actions they are made
 * once, here, so the management API cannot be the door that forgot one — and the console
 * controllers keep their own earlier refusals only so a person gets the same 403 page
 * they always did.
 */
final class IntegrationReach
{
    /**
     * The organization this principal is confined to, or null when it acts with the
     * environment's authority.
     *
     * Only an organization-plane console session is confined; its organization comes from
     * the session, never from input.
     */
    public static function confinedTo(Principal $principal): ?string
    {
        if ($principal instanceof ConsoleSessionPrincipal && $principal->scope()->plane() === ConsolePlane::Organization) {
            return $principal->scope()->requireOrganizationId();
        }

        return null;
    }

    /**
     * Narrow $query to what a confined principal may SEE: its organization's and the
     * environment's own. Unconfined, the environment scope on the model is the bound.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function visible(Builder $query, ?string $confinedTo): Builder
    {
        if ($confinedTo === null) {
            return $query;
        }

        return $query->where(static fn (Builder $scoped): Builder => $scoped
            ->whereNull('organization_id')
            ->orWhere('organization_id', $confinedTo));
    }

    /**
     * Refuse a change to something visible but not this principal's: the environment's
     * own endpoint, seen from an organization's console.
     *
     * @throws ActionRefused
     */
    public static function assertManageable(?string $ownerId, ?string $confinedTo, string $resource): void
    {
        if ($confinedTo !== null && $ownerId !== $confinedTo) {
            throw new ActionRefused('forbidden', ucfirst($resource).' belongs to the environment. Your operator manages it.', 403);
        }
    }

    /**
     * The two inputs {@see self::owner()} reads, described once for every create.
     *
     * @return list<Field>
     */
    public static function ownerFields(): array
    {
        return [
            Field::string('organization_id')->nullable()->max(64)->describe('The organization it belongs to; it carries that organization\'s traffic only. Send this or environment_wide.'),
            Field::boolean('environment_wide')->describe('True to make it the environment\'s own, carrying EVERY organization\'s traffic. Send this or organization_id.'),
        ];
    }

    /**
     * Who a NEW integration belongs to: `organization_id`, or the whole environment when
     * `environment_wide` is true — exactly one, said out loud.
     *
     * Never "organization_id left out, so the environment's". An endpoint with no
     * organization is handed every tenant's events, and a null that arrives from a
     * forgotten field is indistinguishable from one that was meant; the framework's
     * registries split the two calls for the same reason.
     *
     * @throws ActionRefused
     */
    public static function owner(ActionContext $context): ?string
    {
        $confinedTo = self::confinedTo($context->principal);
        $organizationId = $context->nullableString('organization_id');

        if ($context->boolean('environment_wide')) {
            if ($organizationId !== null) {
                throw ActionRefused::because('ambiguous_owner', 'Send organization_id or environment_wide, not both.', 'organization_id');
            }

            if ($confinedTo !== null) {
                throw new ActionRefused('forbidden', 'Only an environment administrator may register an integration for the whole environment.', 403);
            }

            return null;
        }

        if ($organizationId === null) {
            throw ActionRefused::because('owner_required', 'Send organization_id, or environment_wide: true to receive every organization\'s traffic.', 'organization_id');
        }

        if ($confinedTo !== null && $organizationId !== $confinedTo) {
            throw new ActionRefused('forbidden', 'You may only register integrations for your own organization.', 403);
        }

        if (Organization::query()->whereKey($organizationId)->doesntExist()) {
            throw ActionRefused::because('organization_not_found', 'No organization with that organization_id exists in this environment.', 'organization_id');
        }

        return $organizationId;
    }

    /**
     * Refuse an address that is not an absolute http(s) URL before any guard resolves it.
     *
     * The registries' SSRF guards refuse a private address, and can be switched off on a
     * single-tenant install; this one refuses what is not an address at all, always — the
     * console's form request does the same.
     *
     * @throws ActionRefused
     */
    public static function assertUrl(string $url, string $field = 'url'): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['https', 'http'], true)) {
            throw ActionRefused::because('invalid_url', 'The URL must be an absolute http(s) URL.', $field);
        }
    }
}
