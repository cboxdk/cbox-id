<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Platform\ApiKeys\KeyApps;
use Carbon\CarbonImmutable;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Closure;
use Illuminate\Support\Collection;

/**
 * A list of app API keys as rows, with every app and holder named in two batched reads —
 * never one per row. The three key pages are lists that grow with an organization, and the
 * query budget is per page, not per key.
 */
final readonly class AppApiKeyRows
{
    public function __construct(
        private KeyApps $apps,
        private Subjects $subjects,
    ) {}

    /**
     * @param  Collection<int, CustomerApiKey>  $keys
     * @param  Closure(CustomerApiKey): string  $revokeHref
     * @return list<AppApiKeyRowProps>
     */
    public function for(Collection $keys, bool $withHolder, Closure $revokeHref): array
    {
        /** @var list<string> $clientIds */
        $clientIds = $keys->map(static fn (CustomerApiKey $key): string => $key->client_id)->unique()->values()->all();
        $appNames = $this->apps->names($clientIds);

        /** @var list<string> $userIds */
        $userIds = $keys->map(static fn (CustomerApiKey $key): string => $key->user_id)->unique()->values()->all();
        $holders = $withHolder ? $this->subjects->findMany($userIds) : [];

        $now = CarbonImmutable::now();

        return array_values($keys->map(fn (CustomerApiKey $key): AppApiKeyRowProps => AppApiKeyRowProps::from(
            $key,
            // An app deleted since keeps its id on the row, so the key is still traceable.
            $appNames[$key->client_id] ?? $key->client_id,
            $holders[$key->user_id] ?? null,
            $withHolder,
            $revokeHref($key),
            $now,
        ))->all());
    }
}
