<?php

declare(strict_types=1);

namespace App\Rules;

use App\Platform\BreachedPasswords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule that rejects passwords which appear in the HaveIBeenPwned
 * Pwned Passwords corpus. Delegates to {@see BreachedPasswords}, which performs
 * a privacy-preserving k-anonymity lookup and fails open when HIBP is
 * unreachable, so this rule only ever blocks a confirmed breached password.
 */
final class NotBreached implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (app(BreachedPasswords::class)->isBreached($value)) {
            $fail('This password has appeared in a known data breach. Please choose a different one.');
        }
    }
}
