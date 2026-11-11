<?php

declare(strict_types=1);

namespace App\Platform\Shell;

use App\Platform\Console\ConsoleSearch;
use Cbox\Console\Kit\Contracts\SearchProvider;
use Cbox\Console\Kit\Shell\SearchQuery;
use Cbox\Console\Kit\Shell\SearchResult;
use Illuminate\Http\Request;

/**
 * The palette's "Records": users, organizations, apps, management keys (a pasted `cbid_…`
 * finds its key) and audit events, from {@see ConsoleSearch}, in the console the request
 * stands in. Local reads only.
 */
final readonly class IdSearchProvider implements SearchProvider
{
    public function search(Request $request, SearchQuery $query): array
    {
        return self::flatten(app(ConsoleSearch::class)->search($query->term), $query->limit);
    }

    /**
     * @param  array{groups: list<array{key: string, label: string, items: list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>}>}  $results
     * @return list<SearchResult>
     */
    public static function flatten(array $results, int $limit): array
    {
        $out = [];

        foreach ($results['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $out[] = new SearchResult(
                    id: $item['kind'].':'.$item['id'],
                    title: $item['title'],
                    url: $item['href'],
                    subtitle: trim($group['label'].($item['subtitle'] !== null && $item['subtitle'] !== '' ? ' · '.$item['subtitle'] : '')),
                );
            }
        }

        return array_slice($out, 0, max(1, $limit * 4));
    }
}
