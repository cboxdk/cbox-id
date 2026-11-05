<?php

declare(strict_types=1);

namespace App\Platform\AppCatalog;

use App\Platform\Actions\ActionRefused;
use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AppCatalog
{
    public function __construct(private Subjects $subjects, private Memberships $memberships, private FeatureFlags $flags, private IssuerResolver $issuer, private AuditLog $audit) {}

    public function hasPublishedApps(): bool
    {
        return CatalogEntry::query()->where('enabled', true)->exists();
    }

    public function find(string $key): CatalogEntry
    {
        return CatalogEntry::query()->where('key', $key)->firstOrFail();
    }

    public function save(string $key, CatalogEntryData $data, ?AuditActor $actor = null): CatalogEntry
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['key' => 'Use a lowercase app key, starting with a letter (up to 64 characters).']);
        }
        foreach ($data->organizations as $id) {
            if (! Organization::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['organization_ids' => 'Choose organizations in this environment.']);
            }
        }
        foreach ($data->users as $id) {
            if ($this->subjects->find($id) === null) {
                throw ValidationException::withMessages(['user_ids' => 'Choose users in this environment.']);
            }
        }
        foreach ($data->clients as $id) {
            if (! Client::query()->where('client_id', $id)->whereNull('organization_id')->exists()) {
                throw ValidationException::withMessages(['client_ids' => 'Choose environment-wide OAuth clients in this environment.']);
            }
        }
        if ($data->requiredFeature !== null && $this->flags->findByKey($data->requiredFeature) === null) {
            throw ValidationException::withMessages(['required_feature' => 'Choose a feature flag in this environment.']);
        }
        try {
            return DB::transaction(function () use ($key, $data, $actor): CatalogEntry {
                $entry = CatalogEntry::query()->where('key', $key)->lockForUpdate()->first();
                if (($entry->version ?? 0) !== $data->version) {
                    throw new ActionRefused('version_conflict', 'The app changed. Reload it before saving.', 409);
                }
                $entry ??= new CatalogEntry;
                $entry->fill(['key' => $key, 'name' => $data->name, 'description' => $data->description ?: null,
                    'launch_url' => $data->launchUrl, 'enabled' => $data->enabled, 'position' => $data->position,
                    'audience' => $data->audience, 'organization_ids' => $data->organizations, 'user_ids' => $data->users,
                    'client_ids' => $data->clients, 'required_feature' => $data->requiredFeature]);
                if ($data->iconProvided) {
                    $entry->icon_data = $data->icon;
                }
                if (! $entry->exists || $entry->isDirty()) {
                    $entry->version = $data->version + 1;
                    $entry->save();
                    $this->record('app_catalog.saved', $entry, $actor);
                }

                return $entry;
            });
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                throw new ActionRefused('version_conflict', 'This app key was created concurrently. Reload the catalog.', 409);
            }
            throw $e;
        }
    }

    public function delete(string $key, int $version, ?AuditActor $actor = null): void
    {
        DB::transaction(function () use ($key, $version, $actor): void {
            $entry = CatalogEntry::query()->where('key', $key)->lockForUpdate()->firstOrFail();
            if ($entry->version !== $version) {
                throw new ActionRefused('version_conflict', 'The app changed. Reload it before deleting.', 409);
            }
            $entry->delete();
            $this->record('app_catalog.deleted', $entry, $actor);
        });
    }

    private function record(string $action, CatalogEntry $entry, ?AuditActor $actor): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            actorType: $actor->type ?? ActorType::System,
            actorId: $actor?->id,
            targetType: 'catalog_app',
            targetId: $entry->id,
            context: ['key' => $entry->key, 'version' => $entry->version, 'enabled' => $entry->enabled],
        ));
    }

    /** @return list<array<string, mixed>> */
    public function forUser(string $userId, ?string $organizationId = null): array
    {
        if (! $this->subjects->isActive($userId)) {
            return [];
        }
        $membershipIds = [];
        foreach ($this->memberships->forUser($userId) as $membership) {
            if ($membership->status === MembershipStatus::Active) {
                $membershipIds[] = $membership->organization_id;
            }
        }
        $organizations = $membershipIds === [] ? [] : array_values(Organization::query()
            ->whereIn('id', $membershipIds)->where('status', 'active')->get()
            ->map(static fn (Organization $organization): string => $organization->id)->all());
        if ($organizationId !== null && ! in_array($organizationId, $organizations, true)) {
            throw new AuthorizationException('Choose an active organization you belong to.');
        }
        $organizations = $organizationId === null ? $organizations : [$organizationId];
        $apps = [];
        foreach (CatalogEntry::query()->where('enabled', true)->orderBy('position')->orderBy('key')->get() as $entry) {
            $eligible = match ($entry->audience) {
                'everyone' => true,
                'users' => in_array($userId, $entry->user_ids, true),
                'organizations' => array_intersect($organizations, $entry->organization_ids) !== [],
                default => false,
            };
            if (! $eligible) {
                continue;
            }
            if ($entry->required_feature !== null) {
                $contexts = $entry->audience === 'organizations' ? array_values(array_intersect($organizations, $entry->organization_ids)) : ($organizations ?: [null]);
                if (! array_any($contexts, fn (?string $org): bool => $this->flags->isEnabled($entry->required_feature, $userId, $org))) {
                    continue;
                }
            }
            $apps[] = $this->present($entry);
        }

        return $apps;
    }

    /** @return array<string, mixed> */
    public function present(CatalogEntry $entry, bool $admin = false): array
    {
        $hash = $entry->icon_data === null ? null : hash('sha256', $entry->icon_data);
        $item = ['key' => $entry->key, 'name' => $entry->name, 'description' => $entry->description,
            'launch_url' => $entry->launch_url, 'icon_url' => $hash === null ? null : rtrim($this->issuer->issuer(), '/').'/app-icons/'.$entry->id.'/'.$hash,
            'position' => $entry->position, 'version' => $entry->version];

        return $admin ? [...$item, 'enabled' => $entry->enabled, 'audience' => $entry->audience, 'organization_ids' => $entry->organization_ids,
            'user_ids' => $entry->user_ids, 'client_ids' => $entry->client_ids, 'required_feature' => $entry->required_feature] : $item;
    }
}
