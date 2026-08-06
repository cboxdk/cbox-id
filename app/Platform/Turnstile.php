<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile — the CAPTCHA the signup form falls back to when the risk
 * scorer asks for a challenge ({@see RiskGuard::shouldStepUp()}).
 *
 * Turnstile is chosen over a puzzle CAPTCHA deliberately: for the overwhelming
 * majority of humans it is a non-interactive proof (no images, no clicks), it sets
 * no advertising cookies, and it never shows the user a labelling task. It is also
 * only ever rendered on a signup the scorer already flagged — an unconditional
 * CAPTCHA taxes every legitimate signup to stop the small share that isn't.
 *
 * INERT WITHOUT KEYS. A self-hoster with no Turnstile account gets exactly today's
 * behaviour: {@see configured()} is false, {@see verify()} answers true, the widget
 * never renders, and the CSP keeps its tighter script-src. The feature is opt-in by
 * the presence of the two keys and nothing else.
 */
final class Turnstile
{
    /**
     * Cloudflare's token-verification endpoint. A hard-coded constant — no part of
     * this URL comes from user input, so the SSRF-safe URL guards this codebase uses
     * for operator-supplied endpoints (webhooks, SCIM, manifests) do not apply.
     */
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** The single third-party origin Turnstile needs in the CSP. */
    public const ORIGIN = 'https://challenges.cloudflare.com';

    public function __construct(private readonly HttpClient $http) {}

    /** True only when BOTH keys are set — half a configuration is not a feature. */
    public function configured(): bool
    {
        return $this->key('site_key') !== '' && $this->secretKey() !== '';
    }

    /** The public site key for the widget, or '' when Turnstile is not configured. */
    public function siteKey(): string
    {
        return $this->configured() ? $this->key('site_key') : '';
    }

    /**
     * Verify a widget token with Cloudflare. NEVER trust the browser's own success
     * callback: the token is the only evidence, and only Cloudflare can validate it.
     *
     * Returns true when Turnstile is not configured (the feature is inert), and false
     * for a missing, malformed or rejected token — including when Cloudflare cannot be
     * reached. Failing closed is the right default here because the only requests that
     * reach this method are ones the risk scorer already judged elevated; a legitimate
     * signup at that score can retry, an unreachable verifier must not become a bypass.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->configured()) {
            return true;
        }

        $token = trim((string) $token);

        if ($token === '') {
            return false;
        }

        try {
            $response = $this->http
                ->asForm()
                ->timeout(5)
                ->connectTimeout(3)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $this->secretKey(),
                    'response' => $token,
                    'remoteip' => $ip,
                ], static fn (?string $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException $e) {
            Log::warning('turnstile verification unreachable', ['message' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('turnstile verification failed', ['status' => $response->status()]);

            return false;
        }

        $success = $response->json('success') === true;

        if (! $success) {
            // Cloudflare's error codes are diagnostic (bad key, duplicate token,
            // expired) — logged, never shown to the submitter.
            Log::info('turnstile token rejected', ['errors' => $response->json('error-codes')]);
        }

        return $success;
    }

    private function secretKey(): string
    {
        return $this->key('secret_key');
    }

    private function key(string $name): string
    {
        $value = config('services.turnstile.'.$name);

        return is_string($value) ? trim($value) : '';
    }
}
