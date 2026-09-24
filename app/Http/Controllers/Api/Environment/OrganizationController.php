<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Environment\CreateOrganizationRequest;
use App\Http\Requests\Api\Environment\UpdateOrganizationRequest;
use App\Http\Resources\Environment\OrganizationResource;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Exceptions\SlugAlreadyTaken;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Environment plane › organizations. Lists, provisions, renames and archives the
 * organizations (the customer's own tenants) inside one environment. Reads query the hard
 * environment-scoped model directly; writes delegate to the {@see Organizations} and
 * {@see Memberships} services. Every row is confined to the request's host-resolved
 * environment by the platform's deny-by-default tenancy scope — this controller never
 * widens it.
 */
final class OrganizationController extends Controller
{
    use PaginatesEnvironmentResources;

    public function index(Request $request): JsonResponse
    {
        [$limit, $after] = $this->cursor($request);

        return $this->page(
            $this->pageOf(Organization::query(), $limit, $after)->get(),
            $limit,
            OrganizationResource::from(...),
        );
    }

    public function show(string $id): JsonResponse
    {
        $organization = $this->organization($id);

        return $organization === null
            ? $this->notFound('organization')
            : $this->item(OrganizationResource::from($organization));
    }

    /**
     * Create the organization and, when named, make an existing user its OWNER — one
     * transaction, so an app never sees a team that exists with nobody in charge of it.
     */
    public function store(CreateOrganizationRequest $request, Organizations $organizations, Memberships $memberships): JsonResponse
    {
        $slug = $request->slug();

        if ($slug !== null && $organizations->bySlug($slug) !== null) {
            return $this->refuse('slug_taken', 'That slug is already in use in this environment.', 422);
        }

        $parentId = $request->parentId();

        if ($parentId !== null && $this->organization($parentId) === null) {
            return $this->refuse('parent_not_found', 'No organization with that parent_id exists in this environment.', 422);
        }

        $ownerId = $request->ownerUserId();

        // The environment-scoped user store: an id from another environment is no user here.
        if ($ownerId !== null && User::query()->whereKey($ownerId)->doesntExist()) {
            return $this->refuse('user_not_found', 'No user with that owner_user_id exists in this environment.', 422);
        }

        try {
            $organization = DB::transaction(function () use ($request, $organizations, $memberships, $slug, $parentId, $ownerId): Organization {
                $organization = $organizations->create(new NewOrganization(
                    name: $request->name(),
                    slug: $slug ?? $this->freeSlug($organizations, $request->name()),
                    type: $request->type(),
                    parentId: $parentId,
                ));

                // `add()` is the one door that may seat an Owner: on a brand-new
                // organization there is nobody to transfer it from.
                if ($ownerId !== null) {
                    $memberships->add($organization->id, $ownerId, MembershipRole::Owner);
                }

                return $organization;
            });
        } catch (SlugAlreadyTaken) {
            // A concurrent create took it between the check above and the insert.
            return $this->refuse('slug_taken', 'That slug is already in use in this environment.', 422);
        }

        return $this->item(OrganizationResource::from($organization), 201);
    }

    /**
     * Rename, or change the slug, through {@see Organizations::update()} — which announces
     * `organization.updated` and records it. What it records is WHICH fields changed; the
     * values before and after go on the trail here, under the name the consoles already use
     * for a rename, so "what was it called before" has an answer whichever door changed it.
     */
    public function update(UpdateOrganizationRequest $request, string $id, Organizations $organizations, AuditLog $audit): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        $changes = $request->changes();

        if ($changes->isEmpty()) {
            return $this->item(OrganizationResource::from($organization));
        }

        $before = ['name' => $organization->name, 'slug' => $organization->slug];

        try {
            $updated = $organizations->update($organization->id, $changes);
        } catch (SlugAlreadyTaken) {
            return $this->refuse('slug_taken', 'That slug is already in use in this environment.', 422);
        }

        $nameChanged = $updated->name !== $before['name'];
        $slugChanged = $updated->slug !== $before['slug'];

        if ($nameChanged || $slugChanged) {
            $audit->record(new AuditEvent(
                action: 'organization.renamed',
                actorType: ActorType::Service,
                actorId: $this->actingKey()->id,
                organizationId: $updated->id,
                targetType: 'organization',
                targetId: $updated->id,
                context: ['from' => $before['name'], 'to' => $updated->name] + ($slugChanged
                    ? ['slug_from' => $before['slug'], 'slug_to' => $updated->slug]
                    : []),
            ));
        }

        return $this->item(OrganizationResource::from($updated));
    }

    /**
     * Archive — the soft, terminal state {@see Organizations::archive()} writes: rows kept for
     * the audit trail, every member refused from the next request, `organization.deleted`
     * announced. Idempotent: archiving an archived organization answers with it as it is.
     *
     * An organization that owns identity-provider PRODUCTS is a customer of this platform,
     * with projects, environments and a bill; closing one is not something an API key does.
     */
    public function destroy(string $id, Organizations $organizations, OrganizationProjects $projects, PlatformRoot $platformRoot): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        $ownsProducts = $platformRoot->run(
            fn (): bool => $projects->forOrganization($organization->id)->isNotEmpty(),
        ) === true;

        if ($ownsProducts) {
            return $this->refuse(
                'owns_products',
                'This organization owns identity-provider projects on this platform and cannot be archived over the API.',
                409,
            );
        }

        return $this->item(OrganizationResource::from($organizations->archive($organization->id, $this->actingKey()->id)));
    }

    /** The name as a slug, walked to the first one nobody holds — as the console does. */
    private function freeSlug(Organizations $organizations, string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'org';
        $slug = $base;

        for ($n = 2; $organizations->bySlug($slug) !== null; $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
