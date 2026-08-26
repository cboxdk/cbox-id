<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Illuminate\Support\Facades\DB;

/**
 * A SEARCH BOX IS NOT A PATTERN LANGUAGE.
 *
 * `LIKE` reads `%` as "any run of characters" and `_` as "any one character", so a term
 * typed into any console search that reached the query as-is was a wildcard expression
 * somebody wrote by accident. `c_ntoso` matched Contoso; an address containing an
 * underscore — which is most machine-generated ones — matched addresses it has nothing
 * to do with; and a lone `%` listed the entire table on every plane in the install.
 *
 * That is a correctness bug on every list and an information-disclosure one on the
 * operator plane, where the lists span every tenant on the deployment. It was fixed once,
 * in the cross-plane search, and three other lists kept the defect — so it lives here now,
 * as the one way this console builds a LIKE, and the escaping and the driver quirk are
 * stated in one place rather than four.
 */
final readonly class LikeTerm
{
    private function __construct(
        /** The bound value — already escaped, already lower-cased, already wrapped. */
        public string $pattern,
    ) {}

    /** Rows CONTAINING the term, wherever it falls in the column. */
    public static function containing(string $term): self
    {
        // Escaped first so a literal % or _ in the term cannot act as one, then
        // lower-cased for case-insensitive matching across drivers.
        return new self('%'.addcslashes(mb_strtolower($term), '%_\\').'%');
    }

    /**
     * The LIKE expression for a known column, case-insensitive and wildcard-safe.
     *
     * SQLite has no default LIKE escape character, so it must be declared; MySQL and
     * PostgreSQL already treat backslash as the default escape (and parse their string
     * literals differently), so only SQLite gets the explicit clause.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public function sqlFor(string $column): string
    {
        $escape = DB::connection()->getDriverName() === 'sqlite' ? " escape '\\'" : '';

        return "lower({$column}) like ?".$escape;
    }
}
