<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

/**
 * The one place a pasted allow-list becomes a list of origins.
 *
 * Shared by the create form and the edit form because they take the same input in the same
 * shape, and two copies of a splitter is how one of them comes to accept a blank line the
 * other refuses.
 */
final class FrontendKeyOrigins
{
    /**
     * One origin per line, blanks dropped, nothing else touched.
     *
     * NOT normalised: whether `https://example.com/` and `https://example.com` are the
     * same origin is the store's question, and answering it here would mean answering it
     * differently from the API that shares that store.
     *
     * @return list<string>
     */
    public static function parse(string $input): array
    {
        return array_values(array_filter(
            array_map(trim(...), preg_split('/\r\n|\r|\n/', $input) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
