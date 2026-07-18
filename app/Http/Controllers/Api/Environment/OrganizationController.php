<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Environment plane › organizations. Lists and provisions the organizations (the
 * customer's own tenants) inside one environment. Reads query the hard
 * environment-scoped model directly; writes delegate to the {@see Organizations}
 * service. Every row is confined to the request's host-resolved environment by the
 * platform's deny-by-default tenancy scope — this controller never widens it.
 */
final class OrganizationController extends Controller
{
    use PaginatesEnvironmentResources;

    public function index(Request $request): JsonResponse
    {
        [$limit, $after] = $this->cursor($request);

        $query = Organization::query()->orderBy('id')->limit($limit + 1);

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        $rows = $query->get();

        return $this->page($rows, $limit, fn (Organization $o): array => $this->present($o));
    }

    public function show(string $id): JsonResponse
    {
        $organization = Organization::query()->find($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        return response()->json(['data' => $this->present($organization)]);
    }

    public function store(Request $request, Organizations $organizations): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', 'alpha_dash'],
            'type' => ['sometimes', Rule::enum(OrganizationType::class)],
        ]);

        $slug = $request->string('slug')->toString();

        if ($organizations->bySlug($slug) !== null) {
            return response()->json(['error' => 'slug_taken', 'message' => 'That slug is already in use in this environment.'], 422);
        }

        $organization = $organizations->create(new NewOrganization(
            name: $request->string('name')->toString(),
            slug: $slug,
            type: $request->enum('type', OrganizationType::class) ?? OrganizationType::Customer,
        ));

        return response()->json(['data' => $this->present($organization)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'type' => $organization->type->value,
            'status' => $organization->status->value,
            'parent_id' => $organization->parent_id,
        ];
    }
}
