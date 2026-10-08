<?php

declare(strict_types=1);

namespace App\Actions\Provisioning;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Outbound provisioning targets — downstream apps this platform pushes people to over
 * SCIM — as the management API returns them. A helper, not an action.
 *
 * The credential the platform presents downstream (a bearer token, or an OAuth client's
 * secret) is input only: sealed against the target's id and never returned.
 */
final class ProvisioningFields
{
    /**
     * The target this principal may reach, or a 404. An environment-wide target belongs
     * to no organization, so narrowing to one — as an organization administrator always
     * does — never reaches it.
     *
     * @throws ActionRefused
     */
    public static function target(ActionContext $context): ProvisioningConnection
    {
        $organizationId = EnterpriseReach::narrowedTo($context);

        return ProvisioningConnection::query()
            ->whereKey($context->string('id'))
            ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->first() ?? throw ActionRefused::notFound('provisioning target');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ProvisioningConnection $connection): array
    {
        $config = $connection->auth_config;

        return [
            'id' => $connection->id,
            'organization_id' => $connection->organization_id,
            'name' => $connection->name,
            'base_url' => $connection->base_url,
            'auth_scheme' => $connection->auth_scheme->value,
            'token_url' => is_string($config['token_url'] ?? null) ? $config['token_url'] : null,
            'client_id' => is_string($config['client_id'] ?? null) ? $config['client_id'] : null,
            'active' => $connection->status === ConnectionStatus::Active,
            'consecutive_failures' => $connection->consecutive_failures,
            'last_success_at' => Timestamp::of($connection->last_success_at),
            'last_error' => $connection->last_error,
            'created_at' => Timestamp::of($connection->getAttribute('created_at')),
        ];
    }
}
