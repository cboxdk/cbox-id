<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Hris\ValueObjects\HrisSyncOptions;
use Cbox\Id\Directory\Models\Directory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inbound directories — a SCIM endpoint the customer's identity provider posts to, or a
 * Google Workspace / Microsoft Entra / HR-system directory fetched from on a schedule — as
 * the management API returns them. A helper, not an action.
 *
 * A pull directory also answers how its sync is going: its pace, when it last started and
 * how that run went (`last_sync_status`, `last_sync_stats` — counts, and per-record
 * failures by the provider's own employee id, never by name or email), and when the next
 * one is due. An HR system adds which of its fields pass through.
 *
 * NO CREDENTIAL IS EVER RETURNED but the one moment it is minted: a SCIM bearer token is
 * shown in the create and rotate answers only (stored as a hash, so it could not be shown
 * again), and a pull directory's provider credentials are sealed and never read back.
 */
final class DirectoryFields
{
    /**
     * The directory this principal may reach, or a 404.
     *
     * @throws ActionRefused
     */
    public static function directory(ActionContext $context): Directory
    {
        $organizationId = EnterpriseReach::narrowedTo($context);

        return Directory::query()
            ->whereKey($context->string('id'))
            ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->first() ?? throw ActionRefused::notFound('directory');
    }

    /**
     * The directory, refused unless its organization's plan includes directory sync —
     * asked of the DIRECTORY's organization, the one whose people it provisions.
     *
     * @throws ActionRefused
     */
    public static function changeable(ActionContext $context): Directory
    {
        $directory = self::directory($context);

        EnterpriseReach::assertEntitled($directory->organization_id, 'scim');

        return $directory;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Directory $directory, ?string $token = null): array
    {
        $scim = ! $directory->provider->isPull();

        return [
            'id' => $directory->id,
            'organization_id' => $directory->organization_id,
            'name' => $directory->name,
            'provider' => $directory->provider->value,
            'pull' => ! $scim,
            'active' => $directory->status === DirectoryStatus::Active,
            'status' => $directory->status->value,
            'scim_base_url' => $scim ? url('/scim/v2') : null,
            'hris' => $directory->provider->isHris(),
            'last_synced_at' => Timestamp::of($directory->last_synced_at),
            'last_sync_error' => $directory->last_sync_error,
            'last_sync_started_at' => $scim ? null : Timestamp::of($directory->last_sync_started_at),
            'last_sync_status' => $scim ? null : $directory->last_sync_status?->value,
            'last_sync_stats' => $scim ? null : $directory->last_sync_stats,
            'sync_interval_minutes' => $scim ? null : $directory->syncIntervalMinutes(),
            'next_sync_at' => $scim ? null : Timestamp::of($directory->nextSyncAt()),
            'custom_attributes' => $directory->provider->isHris() ? self::hrisOptions($directory)['custom_attributes'] : null,
            'created_at' => Timestamp::of($directory->getAttribute('created_at')),
            ...($token === null ? [] : ['bearer_token' => $token]),
        ];
    }

    /**
     * An HR-system directory's field options as stored, normalised.
     *
     * @return array{custom_attributes: list<string>, field_map: array<string, string>}
     */
    public static function hrisOptions(Directory $directory): array
    {
        $options = HrisSyncOptions::fromMappings($directory->mappings ?? []);

        return ['custom_attributes' => $options->customAttributes, 'field_map' => $options->fieldMap];
    }
}
