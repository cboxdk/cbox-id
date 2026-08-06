<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The app-side copy of the framework's guard. cboxdk/laravel-id carries the same
 * test over its own migrations, and that is exactly why this one is needed: it
 * proved nothing about THIS repository's migrations, so while the framework's
 * tables were converted to `varchar`, thirteen `ulid()` declarations across the
 * app's own tables and two of the console modules kept compiling to `char(26)`.
 *
 * PostgreSQL implements `CHAR` as `bpchar` — blank-padded. A 26-character ULID in a
 * `char(26)` column is fine, but a shorter value comes back to PHP padded out to the
 * declared width, so a strict comparison fails. It surfaced as the environment-scope
 * guard REFUSING a legitimate write on `admin_portal_links`:
 *
 *     row belongs to [env_test                  ], acting as [env_test]
 *
 * Postgres' own `=` and `length()` strip the blanks, which is why the padding is
 * invisible from a SQL client; MySQL strips on retrieval and SQLite has no
 * fixed-width type at all. So the app's own CI — sqlite only until the engines job
 * existed — could never have caught it.
 *
 * A SOURCE scan, not a schema assertion, on purpose: it fails on sqlite in a
 * fraction of a second on the run every contributor makes, where a live-schema check
 * would only speak up in the server-engine job.
 */
it('never declares a blank-padded CHAR column in a migration', function (): void {
    // char(26) via the ULID helpers, char(36) via the UUID ones, and `char()` itself.
    // Use `string($column, $length)` instead: `varchar` does not pad on any supported
    // engine, and so compares exactly once PDO hands the value to PHP.
    $banned = [
        'char', 'ulid', 'ulidMorphs', 'nullableUlidMorphs', 'foreignUlid',
        'uuid', 'uuidMorphs', 'nullableUuidMorphs', 'foreignUuid',
    ];

    $offenders = [];

    // The modules ship their own migrations and are loaded from their own paths, so
    // scanning `database/` alone would miss them — which is how two of them shipped
    // padded columns.
    $roots = array_filter([
        base_path('database'),
        base_path('modules'),
    ], 'is_dir');

    $files = array_merge(...array_map(fn (string $root): array => File::allFiles($root), $roots));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Tokenised rather than grepped, so a method name written inside a comment or
        // a string is not mistaken for a call.
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) $file->getContents()),
            fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        foreach ($tokens as $index => $token) {
            if ($token->id !== T_OBJECT_OPERATOR && $token->id !== T_NULLSAFE_OBJECT_OPERATOR) {
                continue;
            }

            $method = $tokens[$index + 1] ?? null;

            if ($method?->id === T_STRING && in_array($method->text, $banned, true)) {
                $offenders[] = sprintf('%s:%d ->%s()', $file->getRelativePathname(), $method->line, $method->text);
            }
        }
    }

    expect($offenders)->toBe([]);
});
