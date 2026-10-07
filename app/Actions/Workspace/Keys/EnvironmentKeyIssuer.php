<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Keys;

use App\Actions\Workspace\Environments\CreateEnvironment;
use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
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
            throw ActionRefused::because('invalid_scopes', 'Choose at least one scope — a key with none can do nothing.', $field);
        }

        $unoffered = array_values(array_diff($scopes, EnvironmentKeyScopes::offeredValues()));

        if ($unoffered !== []) {
            throw ActionRefused::because('invalid_scopes', 'No management key can carry: '.implode(', ', $unoffered).'.', $field);
        }

        try {
            $issued = $this->keys->issue($environment->id, $name, $scopes, $expiresAt, new KeyProvenance(
                createdByType: InWorkspace::creatorType($principal),
                createdById: $principal->id() !== '' ? $principal->id() : null,
                // A key minted by a key answers to that key's approvals too — moving down a
                // plane is no way to shed them.
                stepUpPolicy: $principal instanceof WorkspaceKeyPrincipal ? $principal->key()->step_up_policy : null,
            ));
        } catch (UnknownApiKeyScope $refused) {
            throw ActionRefused::because('invalid_scopes', $refused->getMessage(), $field);
        }

        InWorkspace::record($principal, $workspaceId, 'organization.environment_key_created', 'environment', $environment->id, [
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
            throw ActionRefused::because('invalid_expiry', 'expires_at must be an ISO 8601 date-time.', $key);
        }

        if (! $at->isFuture()) {
            throw ActionRefused::because('invalid_expiry', 'expires_at must be in the future — a key that has already expired can do nothing.', $key);
        }

        return $at;
    }
}
