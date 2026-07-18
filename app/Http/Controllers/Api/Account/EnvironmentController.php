<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Platform\AccountApiContext;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Account plane › environments. Lists and provisions the environments an account
 * owns. Thin: it maps HTTP to the {@see AccountProvisioner} and the Environment
 * model, nothing more.
 */
final class EnvironmentController extends Controller
{
    public function index(Request $request, AccountApiContext $context): JsonResponse
    {
        $limit = min(100, max(1, $request->integer('limit', 50)));

        $environments = Environment::query()
            ->where('account_id', $context->accountId())
            ->orderBy('created_at')
            ->limit($limit + 1)
            ->get();

        // A consistent envelope: data + meta on every list, with a simple
        // has-more/next-cursor signal (account environments are plan-bounded, so a
        // limit is plenty — the shape stays uniform with the larger env-plane lists).
        $hasMore = $environments->count() > $limit;

        return response()->json([
            'data' => $environments->take($limit)->map(fn (Environment $e): array => $this->present($e))->values()->all(),
            'meta' => ['limit' => $limit, 'has_more' => $hasMore],
        ]);
    }

    public function store(Request $request, AccountApiContext $context, AccountProvisioner $provisioner): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['sometimes', Rule::enum(EnvironmentType::class)],
        ]);

        $account = $context->key()?->account;

        if ($account === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Account not found.'], 404);
        }

        try {
            $environment = $provisioner->addEnvironment(
                $account,
                $request->string('name')->toString(),
                type: $request->enum('type', EnvironmentType::class) ?? EnvironmentType::Production,
            );
        } catch (EnvironmentLimitReached $e) {
            return response()->json([
                'error' => 'environment_limit_reached',
                'message' => $e->getMessage(),
            ], 422);
        }

        // Single resources use the same {data:…} envelope as lists.
        return response()->json(['data' => $this->present($environment)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Environment $environment): array
    {
        $base = config('cbox-id.environments.base_domains', []);
        $first = is_array($base) && isset($base[0]) && is_string($base[0]) ? $base[0] : null;
        $baseDomain = $first ?? request()->getHost();

        return [
            'id' => $environment->id,
            'name' => $environment->name,
            'slug' => $environment->slug,
            'type' => $environment->type->value,
            'status' => $environment->status,
            'domain' => $environment->domain,
            'issuer' => 'https://'.($environment->domain ?? $environment->slug.'.'.$baseDomain),
        ];
    }
}
