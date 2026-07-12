<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a plaintext password against the HaveIBeenPwned "Pwned Passwords"
 * corpus of credentials exposed in known data breaches.
 *
 * K-anonymity model
 * -----------------
 * The password is never transmitted. We compute its SHA-1 hash locally, then
 * send only the first five hex characters of that hash (the "prefix") to the
 * HIBP range API. HIBP replies with every hash suffix it holds under that
 * prefix — typically hundreds — and we match our full suffix against that list
 * on our side. HIBP therefore learns a 5-character bucket that maps to many
 * thousands of possible passwords, never the password or its full hash. We also
 * send the "Add-Padding: true" header so the response is padded to a uniform
 * size, preventing a network observer from inferring the bucket population.
 *
 * Fail-open policy
 * ----------------
 * This is a defence-in-depth signal layered on top of ordinary password rules,
 * not the primary gate. HIBP is a third-party service that can be slow, rate
 * limited, or unreachable. If the request errors or returns a non-success
 * status we return false ("not known to be breached") rather than blocking the
 * user: an outage at HIBP must never prevent a legitimate signup or password
 * change. The failure is logged so operators can spot sustained problems.
 */
final class BreachedPasswords
{
    public function isBreached(string $password): bool
    {
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::withHeaders(['Add-Padding' => 'true'])
                ->timeout(3)
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");
        } catch (Throwable $e) {
            Log::warning('Pwned Passwords lookup failed; failing open.', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Pwned Passwords lookup returned a non-success status; failing open.', [
                'status' => $response->status(),
            ]);

            return false;
        }

        foreach (preg_split('/\r\n|\r|\n/', $response->body()) as $line) {
            $parts = explode(':', trim($line), 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$lineSuffix, $count] = $parts;

            if (strcasecmp($lineSuffix, $suffix) === 0) {
                return (int) $count > 0;
            }
        }

        return false;
    }
}
