<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A TEXTAREA OF URIS, every line of which must be safe to hand a browser to.
 *
 * The console asks for redirect and sign-out URIs as one field with one per line, because
 * that is how a person holds the list in their head. Validation is per FIELD, so the rule
 * has to be about the field: {@see SecureRedirectUri} answers for one URI, this answers
 * for the list.
 *
 * The MESSAGE is a constructor argument rather than a fixed string. The two fields that
 * use this are the same rule and different advice — one is where sign-in returns people,
 * the other is where sign-out does — and a shared sentence that named neither is what the
 * generic "the value is invalid" refusal already was.
 */
final readonly class SecureUriLines implements ValidationRule
{
    public function __construct(private string $message) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        foreach (self::split($value) as $uri) {
            if (! SecureRedirectUri::isSecure($uri)) {
                $fail($this->message);

                return;
            }
        }
    }

    /**
     * The non-empty lines of the field.
     *
     * Public and static because the caller that WRITES the list needs exactly the same
     * split the rule validated — two implementations of "what counts as a line" is one
     * refactor away from storing a URI nothing checked.
     *
     * @return list<string>
     */
    public static function split(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n]+/', $value) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
