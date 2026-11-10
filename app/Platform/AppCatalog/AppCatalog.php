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
use Illuminate\Support\Uri;
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
            throw ValidationException::withMessages(['key' => __('console.catalog_invalid_key')]);
        }
        foreach ($data->organizations as $id) {
            if (! Organization::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['organization_ids' => __('console.catalog_choose_organizations')]);
            }
        }
        foreach ($data->users as $id) {
            if ($this->subjects->find($id) === null) {
                throw ValidationException::withMessages(['user_ids' => __('console.catalog_choose_users')]);
            }
        }
        foreach ($data->clients as $id) {
            if (! Client::query()->where('client_id', $id)->whereNull('organization_id')->exists()) {
                throw ValidationException::withMessages(['client_ids' => __('console.catalog_choose_clients')]);
            }
        }
        if ($data->requiredFeature !== null && $this->flags->findByKey($data->requiredFeature) === null) {
            throw ValidationException::withMessages(['required_feature' => __('console.catalog_choose_feature')]);
        }
        try {
            return DB::transaction(function () use ($key, $data, $actor): CatalogEntry {
                $entry = CatalogEntry::query()->where('key', $key)->lockForUpdate()->first();
                if ($entry === null && $data->version !== 0) {
                    throw ActionRefused::notFound('catalog_app');
                }
                if (($entry->version ?? 0) !== $data->version) {
                    throw new ActionRefused('version_conflict', __('console.catalog_changed_save'), 409);
                }
                $entry ??= new CatalogEntry;
                $entry->fill(['key' => $key, 'name' => $data->name, 'description' => $data->description ?: null,
                    'launch_url' => $data->launchUrl, 'enabled' => $data->enabled, 'position' => $data->position,
                    'audience' => $data->audience, 'organization_ids' => $data->organizations, 'user_ids' => $data->users,
                    'client_ids' => $data->clients, 'required_feature' => $data->requiredFeature, ...$data->shell]);
                if ($data->iconProvided) {
                    $entry->icon_data = $data->icon;
                }
                if ($data->iconDarkProvided) {
                    $entry->icon_dark_data = $data->iconDark;
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
                throw new ActionRefused('version_conflict', __('console.catalog_created_concurrently'), 409);
            }
            throw $e;
        }
    }

    public function delete(string $key, int $version, ?AuditActor $actor = null): void
    {
        DB::transaction(function () use ($key, $version, $actor): void {
            $entry = CatalogEntry::query()->where('key', $key)->lockForUpdate()->firstOrFail();
            if ($entry->version !== $version) {
                throw new ActionRefused('version_conflict', __('console.catalog_changed_delete'), 409);
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

    /**
     * The apps one person has here, in catalog order.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(string $userId, ?string $organizationId = null): array
    {
        return $this->catalogFor($userId, $organizationId)['apps'];
    }

    /**
     * What the switcher shows one person: the apps they have ("has", `available: true`) and
     * the published apps they could ask for ("not yet", `available: false`), each with
     * where to ask. An app only becomes "not yet" when it has an `admission_url` and is
     * promoted; a `users`-audience app that does not name this person is never listed,
     * so nobody learns a private app exists.
     *
     * @return array{apps: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}
     */
    public function catalogFor(string $userId, ?string $organizationId = null): array
    {
        if (! $this->subjects->isActive($userId)) {
            return ['apps' => [], 'unavailable' => []];
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
            throw new AuthorizationException(__('console.catalog_choose_membership'));
        }
        $organizations = $organizationId === null ? $organizations : [$organizationId];
        $apps = [];
        $unavailable = [];
        foreach (CatalogEntry::query()->where('enabled', true)->orderBy('position')->orderBy('key')->get() as $entry) {
            if ($this->eligible($entry, $userId, $organizations)) {
                $item = [...$this->present($entry), 'available' => true];
                // The published launch endpoint starts authorization in the destination's
                // own session. Organization is a request, never an access grant.
                if ($organizationId !== null) {
                    $item['launch_url'] = (string) Uri::of($entry->launch_url)
                        ->withQuery(['organization' => $organizationId]);
                }
                $apps[] = $item;

                continue;
            }
            if ($entry->audience === 'users' || $entry->admission_url === null || ! $entry->promote_when_unavailable) {
                continue;
            }
            $item = $this->present($entry);
            unset($item['launch_url']);
            $item['available'] = false;
            $item['admission_url'] = $organizationId === null ? $entry->admission_url
                : (string) Uri::of($entry->admission_url)->withQuery(['organization' => $organizationId]);
            $unavailable[] = $item;
        }

        return ['apps' => $apps, 'unavailable' => $unavailable];
    }

    /**
     * The list `meta` both catalog reads answer with: the "not yet" apps travel in today's
     * `{data, meta}` envelope, and the revision covers both lists, so a client that caches
     * by revision sees an app move from one to the other.
     *
     * @param  array{apps: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}  $catalog
     * @return array{revision: string, refresh_after: int, unavailable: list<array<string, mixed>>}
     */
    public static function meta(array $catalog): array
    {
        return ['revision' => self::revision($catalog), 'refresh_after' => 60, 'unavailable' => $catalog['unavailable']];
    }

    /** @param  array{apps: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}  $catalog */
    public static function revision(array $catalog): string
    {
        return hash('sha256', json_encode($catalog['unavailable'] === [] ? $catalog['apps'] : $catalog, JSON_THROW_ON_ERROR));
    }

    /** @param  list<string>  $organizations */
    private function eligible(CatalogEntry $entry, string $userId, array $organizations): bool
    {
        $eligible = match ($entry->audience) {
            'everyone' => true,
            'users' => in_array($userId, $entry->user_ids, true),
            'organizations' => array_intersect($organizations, $entry->organization_ids) !== [],
            default => false,
        };
        if (! $eligible || $entry->required_feature === null) {
            return $eligible;
        }
        $contexts = $entry->audience === 'organizations' ? array_values(array_intersect($organizations, $entry->organization_ids)) : ($organizations ?: [null]);

        return array_any($contexts, fn (?string $org): bool => $this->flags->isEnabled($entry->required_feature, $userId, $org));
    }

    /** @return array<string, mixed> */
    public function present(CatalogEntry $entry, bool $admin = false): array
    {
        $item = ['key' => $entry->key, 'name' => $entry->name, 'description' => $entry->description, 'purpose' => $entry->purpose, 'hue' => $entry->hue,
            'launch_url' => $entry->launch_url, 'icon_url' => $this->iconUrl($entry, $entry->icon_data),
            'icon_dark_url' => $this->iconUrl($entry, $entry->icon_dark_data), 'position' => $entry->position, 'version' => $entry->version];

        return $admin ? [...$item, 'enabled' => $entry->enabled, 'audience' => $entry->audience, 'organization_ids' => $entry->organization_ids,
            'user_ids' => $entry->user_ids, 'client_ids' => $entry->client_ids, 'required_feature' => $entry->required_feature,
            'admission_url' => $entry->admission_url, 'promote_when_unavailable' => $entry->promote_when_unavailable] : $item;
    }

    /** Content-addressed, so a new upload is a new URL and caches never serve a stale icon. */
    private function iconUrl(CatalogEntry $entry, ?string $data): ?string
    {
        return $data === null ? null : rtrim($this->issuer->issuer(), '/').'/app-icons/'.$entry->id.'/'.hash('sha256', $data);
    }
}
