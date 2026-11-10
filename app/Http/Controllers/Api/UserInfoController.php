<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Cbox\Id\Api\Http\Controllers\UserInfoController as FrameworkUserInfoController;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET|POST /oauth/userinfo`, as the framework answers it plus each organization's slug:
 * `org_slug` beside `org`, and `slug` on every entry of the `organizations` claim. A Cbox
 * app addresses an organization as `/orgs/{slug}`, the same slug in every app, so it reads
 * it here instead of asking the management API.
 *
 * Additive only. `sub`, `org` and each entry's `id` are unchanged, and the slug is read
 * live like `org_name`, so a renamed organization shows on the next read. Bound over the
 * framework's controller so its route keeps its middleware (see
 * {@see Discovery\OpenIdConfigurationController}).
 */
final class UserInfoController extends FrameworkUserInfoController
{
    public function __invoke(Request $request): JsonResponse
    {
        $response = parent::__invoke($request);
        $claims = $response->getData(true);
        if ($response->getStatusCode() !== 200 || ! is_array($claims)) {
            return $response;
        }

        $orgId = is_string($claims['org'] ?? null) ? $claims['org'] : null;
        $organizations = is_array($claims['organizations'] ?? null) ? $claims['organizations'] : [];
        $ids = array_filter([$orgId, ...array_column($organizations, 'id')], is_string(...));
        if ($ids === []) {
            return $response;
        }

        /** @var array<string, string> $slugs */
        $slugs = Organization::query()->whereIn('id', array_values(array_unique($ids)))->pluck('slug', 'id')->all();
        if ($orgId !== null && isset($slugs[$orgId])) {
            $claims['org_slug'] = $slugs[$orgId];
        }
        if (isset($claims['organizations']) && is_array($claims['organizations'])) {
            $claims['organizations'] = array_map(
                static fn (mixed $entry): mixed => is_array($entry) && is_string($entry['id'] ?? null) && isset($slugs[$entry['id']])
                    ? [...$entry, 'slug' => $slugs[$entry['id']]] : $entry,
                $claims['organizations'],
            );
        }

        return $response->setData($claims);
    }
}
