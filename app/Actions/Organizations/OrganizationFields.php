<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Http\Resources\Environment\OrganizationResource;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\OrganizationTarget;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Shared lookups for the organization actions. A helper, not an action.
 *
 * Every organization is named EXPLICITLY — in the path, or as `organization_id` — and never
 * taken from whichever organization a console happens to be looking at: an action is the
 * same change from every door, and session state is not something an API key or an agent
 * has.
 */
final class OrganizationFields
{
    /** How long a metadata key and value may be — the console form's own limits. */
    private const int KEY_MAX = 120;

    private const int VALUE_MAX = 500;

    /**
     * The organization named in the path, in THIS environment — the model is hard
     * environment-scoped, so another environment's id resolves to nothing and answers the
     * same 404 as one that never existed. A person on an organization's own console may only
     * name their own ({@see OrganizationTarget}).
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function find(ActionContext $context, string $id): Organization
    {
        OrganizationTarget::check($context, $id, inPath: true);

        return Organization::query()->whereKey($id)->first() ?? throw ActionRefused::notFound('organization');
    }

    /**
     * `Organization` in the spec, with the free-form metadata an integrator keeps on it.
     *
     * @return array<string, mixed>
     */
    public static function present(Organization $organization): array
    {
        $metadata = self::metadataOf($organization);

        return [...OrganizationResource::from($organization), 'metadata' => $metadata === [] ? null : $metadata];
    }

    /**
     * @return array<string, string>
     */
    public static function metadataOf(Organization $organization): array
    {
        $stored = $organization->settings['metadata'] ?? [];
        $out = [];

        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /**
     * The metadata a caller sent, as the string → string map it is kept as: blank keys
     * dropped, everything trimmed. A value that is not text is refused rather than cast — it
     * is a payload somebody built, not something a person typed.
     *
     * @param  array<mixed>  $sent
     * @return array<string, string>
     *
     * @throws ActionRefused
     */
    public static function metadata(array $sent): array
    {
        $out = [];

        foreach ($sent as $key => $value) {
            $key = trim((string) $key);

            if ($key === '') {
                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                throw ActionRefused::because('invalid_metadata', "The metadata value for [{$key}] must be text.", 'metadata');
            }

            $value = trim(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);

            if (mb_strlen($key) > self::KEY_MAX || mb_strlen($value) > self::VALUE_MAX) {
                throw ActionRefused::because('invalid_metadata', 'A metadata key may be at most '.self::KEY_MAX.' characters and a value at most '.self::VALUE_MAX.'.', 'metadata');
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
