<?php

declare(strict_types=1);

namespace App\Platform\NativeProduct;

use App\Platform\Actions\ActionRefused;
use App\Platform\OrganizationCapabilities;
use Cbox\Id\AccessControl\Contracts\PermissionDecisions;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Routing\Route;

/** Fresh native-app authority. Product admission and environment row bounds are additional gates. */
final readonly class NativeProductAccess
{
    public function __construct(private PlatformRoot $root, private NativeProductClient $client, private PermissionDecisions $decisions) {}

    public function forWebSubject(string $subjectId, string $organizationId): OrganizationCapabilities
    {
        try {
            $request = request();
            if (! $request->route() instanceof Route || ! $request->isMethodSafe()) {
                return $this->forSubject($subjectId, $organizationId);
            }

            $key = self::class.':'.hash('sha256', json_encode([$this->root->model()?->id, $subjectId, $organizationId], JSON_THROW_ON_ERROR));
            $existing = $request->attributes->get($key);
            if ($existing instanceof OrganizationCapabilities) {
                return $existing;
            }

            $capabilities = $this->forSubject($subjectId, $organizationId);
            $request->attributes->set($key, $capabilities);

            return $capabilities;
        } catch (ActionRefused $exception) {
            abort($exception->status, $exception->getMessage());
        }
    }

    public function forSubject(string $subjectId, string $organizationId): OrganizationCapabilities
    {
        $clientId = $this->client->clientId();

        return $this->root->run(function () use ($subjectId, $organizationId, $clientId): OrganizationCapabilities {
            // Ownership needs no RBAC assignments, but every standing condition must
            // still be read live in the platform root before taking that shortcut.
            $membership = app(TenantContext::class)->runAs(GenericTenant::of($organizationId), fn () => Membership::query()
                ->join('organizations', function (JoinClause $join): void {
                    $join->on('organizations.id', '=', 'memberships.organization_id')
                        ->on('organizations.environment_id', '=', 'memberships.environment_id');
                })
                ->join('users', function (JoinClause $join): void {
                    $join->on('users.id', '=', 'memberships.user_id')
                        ->on('users.environment_id', '=', 'memberships.environment_id');
                })
                ->where('memberships.organization_id', $organizationId)->where('memberships.user_id', $subjectId)
                ->where('memberships.status', 'active')->where('organizations.status', 'active')->where('users.status', 'active')
                ->first(['memberships.role']));
            if ($membership === null) {
                return OrganizationCapabilities::fromAppPermissions(false, []);
            }
            if ($membership->role === MembershipRole::Owner) {
                return OrganizationCapabilities::fromAppPermissions(true, []);
            }

            $decision = $this->decisions->decide($subjectId, $organizationId, $clientId, NativeProductManifest::PERMISSIONS);
            if ($decision->userId !== $subjectId || $decision->organizationId !== $organizationId || $decision->clientId !== $clientId) {
                throw new ActionRefused('native_product_unavailable', 'ID product access could not be verified.', 503);
            }
            if (! $decision->organizationActive || $decision->organizationRole === null) {
                return OrganizationCapabilities::fromAppPermissions(false, []);
            }
            $permissions = [];
            foreach ($decision->checks as $check) {
                if ($check->allowed && in_array($check->permission, NativeProductManifest::PERMISSIONS, true)) {
                    $permissions[] = $check->permission;
                }
            }

            return OrganizationCapabilities::fromAppPermissions($decision->organizationRole === MembershipRole::Owner, $permissions);
        }) ?? throw new ActionRefused('native_product_unavailable', 'ID product access could not be verified.', 503);
    }
}
