<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Keys;

use App\Actions\OrgPlane\Environments\CreateEnvironment;
use App\Actions\OrgPlane\InOrganization;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\FrameworkMessage;
use App\Platform\Actions\Principal\OrganizationKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\EnvironmentKeyScopes;
use Carbon\CarbonImmutable;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Exceptions\UnknownApiKeyScope;
use Cbox\Id\Platform\ValueObjects\IssuedEnvironmentApiKey;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Throwable;

/**
 * Minting an environment's management key from the workspace — the one way both
 * {@see CreateEnvironmentKey} and {@see CreateEnvironment}'s `initial_key` do it, so a key
 * made by either is refused, recorded and attributed alike. A helper, not an action.
 *
 * The scopes are the ones the key form OFFERS ({@see EnvironmentKeyScopes::offered()}),
 * not every case the framework knows: a scope no route requires is a promise the API
 * cannot keep. The key records who minted it — a person or a workspace key — and the act
 * is on the WORKSPACE's log, where the console has always recorded a key: a key is the
 * workspace's credential, not the act of one of the environment's customers.
 */
final readonly class EnvironmentKeyIssuer
{
    public function __construct(private EnvironmentApiKeys $keys) {}

    /**
     * @param  list<string>  $scopes
     * @param  string  $field  Where a refusal about the input lands (`scopes`, `initial_key.scopes`).
     *
     * @throws ActionRefused
     */
    public function issue(
        Principal $principal,
        string $workspaceId,
        Environment $environment,
        string $name,
        array $scopes,
        ?CarbonImmutable $expiresAt,
        string $field = 'scopes',
    ): IssuedEnvironmentApiKey {
        $scopes = array_values(array_unique($scopes));

        if ($scopes === []) {
            throw ActionRefused::because('invalid_scopes', new ActionMessage('console.choose_at_least_one_scope_a_key_with_none_can_do_nothin_59eacb6e'), $field);
        }

        $unoffered = array_values(array_diff($scopes, EnvironmentKeyScopes::offeredValues()));

        if ($unoffered !== []) {
            throw ActionRefused::because('invalid_scopes', new ActionMessage('console.no_management_key_can_carry_part1_2ad24a53', ['part1' => (string) (implode(', ', $unoffered))]), $field);
        }

        try {
            $issued = $this->keys->issue($environment->id, $name, $scopes, $expiresAt, new KeyProvenance(
                createdByType: InOrganization::creatorType($principal),
                createdById: InOrganization::creatorId($principal),
                // A key minted by a key answers to that key's approvals too — moving down a
                // plane is no way to shed them.
                stepUpPolicy: $principal instanceof OrganizationKeyPrincipal ? $principal->key()->step_up_policy : null,
            ));
        } catch (UnknownApiKeyScope $refused) {
            throw ActionRefused::because('invalid_scopes', FrameworkMessage::from($refused), $field);
        }

        InOrganization::record($principal, $workspaceId, 'organization.environment_key_created', 'environment', $environment->id, [
            'key_id' => $issued->key->id,
            'name' => $name,
            'scopes' => $scopes,
            'expires_at' => $issued->key->expires_at?->toIso8601String(),
        ]);

        return $issued;
    }

    /**
     * When a key asked for at `$key` stops working: null for never, or a moment in the
     * future.
     *
     * @throws ActionRefused
     */
    public static function expiry(ActionContext $context, string $key): ?CarbonImmutable
    {
        $value = data_get($context->input, $key);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::parse(is_scalar($value) ? (string) $value : '');
        } catch (Throwable) {
            throw ActionRefused::because('invalid_expiry', new ActionMessage('console.expires_at_must_be_an_iso_8601_date_time_59d532ae'), $key);
        }

        if (! $at->isFuture()) {
            throw ActionRefused::because('invalid_expiry', new ActionMessage('console.expires_at_must_be_in_the_future_a_key_that_has_already_80eeeed0'), $key);
        }

        return $at;
    }
}
