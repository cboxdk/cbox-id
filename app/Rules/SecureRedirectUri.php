<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A redirect / callback URI safe to send an OAuth authorization code or a SAML
 * assertion to: HTTPS on any host, plain HTTP only on loopback (RFC 8252 §7.3,
 * native/CLI), or a reverse-domain private-use scheme (RFC 8252 §7.1). Cleartext
 * `http://` on a public host is refused — the user authenticates to the IdP over
 * TLS, so the code/assertion must not then travel to the client/SP in the clear.
 *
 * Mirrors the server-side policy in laravel-id's DynamicClientRegistrar, so a URI
 * an admin types into the console is held to the same bar as a dynamically
 * registered one.
 */
final class SecureRedirectUri implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isSecure($value)) {
            $fail('The :attribute must use https (http is allowed only on localhost), be absolute, and carry no fragment.');
        }
    }

    /** Whether $uri is safe to receive an OAuth code / SAML assertion. */
    public static function isSecure(string $uri): bool
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;

        // Native-app private-use scheme: must be a reverse-domain name (RFC 8252 §7.1),
        // which also refuses dangerous single-word schemes (javascript:, data:, …).
        if ($scheme !== 'http' && $scheme !== 'https') {
            return str_contains($scheme, '.');
        }

        if ($scheme === 'https' && $host !== null) {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
