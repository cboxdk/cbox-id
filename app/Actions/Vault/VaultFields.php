<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\Console\VaultScope;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Illuminate\Database\Eloquent\Builder;

/**
 * The token vault's management surface — the downstream credentials an organization's
 * apps and agents present to third parties — as the management API returns it. A helper,
 * not an action.
 *
 * WHOSE SECRETS is the vault's own boundary ({@see VaultOwner}), and the owner comes from
 * the CALLER, never from the row being acted on: `organization_id` names the organization
 * whose secrets these are, and none means the ENVIRONMENT's own unowned secrets — a
 * separate collection, not "all of them", exactly as the environment console with no
 * organization chosen ({@see VaultScope}). The framework filters every mutation by that
 * owner in its own query, so a secret outside it is indistinguishable from a missing one.
 *
 * THE STORED VALUE IS NEVER RETURNED — not by a read, not by a store or a rotation. It is
 * in the clear only in the request that sets it, and sealed before that request ends.
 */
final class VaultFields
{
    public static function ownerField(): Field
    {
        return Field::string('organization_id')->nullable()->max(64)
            ->describe('The organization whose vault this is. Left out: the environment\'s own secrets, a separate collection.');
    }

    /** @throws ActionRefused */
    public static function owner(ActionContext $context): ?VaultOwner
    {
        $organizationId = EnterpriseReach::narrowedTo($context);

        return $organizationId === null ? null : VaultOwner::organization($organizationId);
    }

    /**
     * @return Builder<VaultSecret>
     */
    public static function secrets(?VaultOwner $owner): Builder
    {
        return VaultSecret::query()->when(
            $owner === null,
            static fn (Builder $query): Builder => $query->whereNull('owner_type')->whereNull('owner_id'),
            static fn (Builder $query): Builder => $query->where('owner_type', $owner?->type->value)->where('owner_id', $owner?->id),
        );
    }

    /** @throws ActionRefused */
    public static function secret(ActionContext $context): VaultSecret
    {
        return self::secrets(self::owner($context))->whereKey($context->string('id'))->first()
            ?? throw ActionRefused::notFound('secret');
    }

    /** @throws ActionRefused */
    public static function assertLive(VaultSecret $secret, string $field): void
    {
        if ($secret->isRevoked()) {
            throw ActionRefused::because('secret_revoked', 'This secret is revoked — no client can lease it, and it can no longer be changed.', $field);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(VaultSecret $secret, bool $withGrants = false): array
    {
        $payload = [
            'id' => $secret->id,
            'organization_id' => $secret->owner_type === 'organization' ? $secret->owner_id : null,
            'name' => $secret->name,
            'provider' => $secret->provider,
            'status' => $secret->isRevoked() ? 'revoked' : ($secret->isExpired() ? 'expired' : 'active'),
            'revoked' => $secret->isRevoked(),
            'expires_at' => Timestamp::of($secret->expires_at),
            'rotated_at' => Timestamp::of($secret->rotated_at),
            'created_at' => Timestamp::of($secret->getAttribute('created_at')),
        ];

        if ($withGrants) {
            // Live grants only: a revoked grant authorizes nothing, and listing it beside
            // the live ones invites reading the list as "who has access".
            $payload['grants'] = array_values(VaultGrant::query()
                ->where('secret_id', $secret->id)
                ->whereNull('revoked_at')
                ->orderBy('client_id')
                ->pluck('client_id')
                ->all());
        }

        return $payload;
    }
}
