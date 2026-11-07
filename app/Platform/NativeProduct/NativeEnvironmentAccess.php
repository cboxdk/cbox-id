<?php

declare(strict_types=1);

namespace App\Platform\NativeProduct;

use App\Platform\Actions\ActionRefused;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;

/** Environment selection only. Native app permissions and admission remain additional gates. */
final readonly class NativeEnvironmentAccess
{
    public function __construct(private PlatformRoot $root, private Memberships $members) {}

    public function version(Membership $member): string
    {
        return $this->root->run(function () use ($member): string {
            $member = $this->members->of($member->organization_id, $member->user_id) ?? throw new ActionRefused('not_found', 'Member not found.', 404);
            $ids = array_values(array_filter($member->environments()->pluck('environments.id')->all(), 'is_string'));
            sort($ids);

            return hash('sha256', json_encode([$member->all_environments === true, $ids], JSON_THROW_ON_ERROR));
        }) ?? throw new ActionRefused('native_product_unavailable', 'The ID platform root is unavailable.', 503);
    }

    /** @param list<string> $ids */
    public function replace(Membership $member, bool $all, array $ids, string $version): void
    {
        $this->root->run(fn () => DB::transaction(function () use ($member, $all, $ids, $version): void {
            $locked = app(TenantContext::class)->runAs(
                GenericTenant::of($member->organization_id),
                fn () => Membership::query()->whereKey($member->id)->lockForUpdate()->firstOrFail(),
            );
            if ($locked->role === MembershipRole::Owner) {
                throw new ActionRefused('not_manageable', 'The current owner has access to all environments.', 422);
            }
            if (! hash_equals($this->version($locked), $version)) {
                throw new ActionRefused('environment_access_changed', 'This member’s environment access changed. Reload it before saving.', 409, 'environment_access_version');
            }
            $this->members->setEnvironmentAccess($locked->organization_id, $locked->user_id, $all, $ids);
        }));
    }

    /** @return list<string> */
    public function ids(string $organizationId, string $subjectId): array
    {
        return $this->root->run(function () use ($organizationId, $subjectId): array {
            $member = $this->members->of($organizationId, $subjectId);
            if ($member === null || $member->status !== MembershipStatus::Active) {
                return [];
            }
            if ($member->role === MembershipRole::Owner) {
                return array_values(array_filter(Environment::query()->whereIn('project_id', Project::query()->where('organization_id', $organizationId)->select('id'))->orderBy('id')->pluck('id')->all(), 'is_string'));
            }

            return $this->members->accessibleEnvironmentIds($organizationId, $subjectId);
        }) ?? [];
    }
}
