<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use App\Platform\Actions\ActionDefinition;

/**
 * The copy of an action's input an approval keeps, so the person approving it can read
 * what they are saying yes to — with every secret taken out first.
 *
 * TWO NETS, because either alone misses something. The action's own `redact` list names
 * the secrets it RETURNS (a key's `token`); an input can carry secrets too (a social
 * provider's `client_secret`, a log stream's bearer `secret`) that no list names. So a field
 * is replaced when the action names it, or when its name says it is one — `secret`,
 * `password`, `token`, `private_key`, `certificate` and their `client_`/`signing_` forms —
 * matched on the WHOLE last segment, so `secret_id` and `access_token_ttl` (an id and a
 * number) are kept, and a stored copy never has to be trusted to have guessed right about
 * anything else.
 *
 * Long strings are cut, so a PEM or a base64 logo pasted into an input is a line on the
 * approval rather than a megabyte in the table. Nothing here is ever run: the request the
 * agent repeats is what runs, checked against the digest of its real input.
 */
final class ApprovalInput
{
    /** Shown in place of a value that was removed. */
    public const string REDACTED = '[redacted]';

    private const int MAX_STRING = 300;

    private const int MAX_ITEMS = 50;

    private const string SECRET_NAME = '/^(?:[a-z0-9]+_)*(?:secret|password|passphrase|token|private_key|certificate|credentials?|api_key)$/i';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function redact(ActionDefinition $action, array $input): array
    {
        /** @var array<string, mixed> $redacted */
        $redacted = self::walk($input, '', $action->redact);

        return $redacted;
    }

    /** Whether a field name, on its own, says it carries a secret. */
    public static function isSecretName(string $name): bool
    {
        return preg_match(self::SECRET_NAME, $name) === 1;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $named
     * @return array<mixed>
     */
    private static function walk(array $value, string $prefix, array $named): array
    {
        $out = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if (++$count > self::MAX_ITEMS) {
                break;
            }

            $path = is_int($key) ? $prefix : ltrim($prefix.'.'.$key, '.');

            if (is_string($key) && (self::isSecretName($key) || in_array($path, $named, true))) {
                $out[$key] = $item === null ? null : self::REDACTED;

                continue;
            }

            $out[$key] = match (true) {
                is_array($item) => self::walk($item, $path, $named),
                is_string($item) && mb_strlen($item) > self::MAX_STRING => mb_substr($item, 0, self::MAX_STRING).'…',
                default => $item,
            };
        }

        return $out;
    }
}
