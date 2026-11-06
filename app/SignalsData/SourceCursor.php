<?php

declare(strict_types=1);

namespace App\SignalsData;

use Illuminate\Validation\ValidationException;

/** A cursor carries no authority; the credential is checked again on every page. */
final class SourceCursor
{
    public function encode(string $environment, string $generation, int $sequence): string
    {
        $payload = base64_encode(json_encode([$environment, $generation, $sequence], JSON_THROW_ON_ERROR));

        return $payload.'.'.hash_hmac('sha256', 'signals-source-v1:'.$payload, config()->string('app.key'));
    }

    public function decode(?string $cursor, string $environment, string $generation): int
    {
        if ($cursor === null) {
            return 0;
        }
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || ! hash_equals(hash_hmac('sha256', 'signals-source-v1:'.$parts[0], config()->string('app.key')), $parts[1])) {
            throw ValidationException::withMessages(['after' => 'Invalid source cursor.']);
        }
        $decoded = base64_decode($parts[0], true);
        $position = $decoded === false ? null : json_decode($decoded, true);
        if (! is_array($position) || count($position) !== 3 || ($position[0] ?? null) !== $environment
            || ($position[1] ?? null) !== $generation || ! is_int($position[2] ?? null) || $position[2] < 0) {
            throw ValidationException::withMessages(['after' => 'Source scope or generation changed. Restart the import explicitly.']);
        }

        return $position[2];
    }
}
