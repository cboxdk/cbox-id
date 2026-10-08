<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Preflight;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Support\SafeFederationUrl;
use Cbox\Id\Kernel\Ssrf\UrlVerification;
use Cbox\Id\Provisioning\Exceptions\UnsafeScimUrl;
use Cbox\Id\Provisioning\Support\SafeScimUrl;
use Cbox\Id\Webhooks\Support\SafeWebhookUrl;
use Cbox\LaravelSiem\Exceptions\UnsafeStreamUrl;
use Cbox\LaravelSiem\Support\SafeStreamUrl;
use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\Exceptions\BlockedUrl;

/**
 * The SSRF guard of each outbound plane, asked BEFORE an action is held for approval
 * ({@see Preflight}) and answered as the action would have answered it afterwards.
 *
 * Each plane's registry guards what it stores — the last word, and it stays. These are the
 * same guards, with the same on/off toggles, asked early so a URL that will be refused is
 * refused before a person is asked to approve the request carrying it. The refusals are
 * word for word what each action says when its registry refuses, so a caller sees one
 * `unsafe_url` whichever of the two answered.
 */
final class OutboundUrl
{
    /**
     * A webhook endpoint: public and HTTPS only ({@see SafeWebhookUrl}).
     *
     * @throws ActionRefused
     */
    public static function assertWebhook(string $url, string $field = 'url'): void
    {
        IntegrationReach::assertUrl($url, $field);

        if (! SafeWebhookUrl::isSafe($url)) {
            throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public HTTPS endpoint.', $field);
        }
    }

    /**
     * An inline hook's endpoint. The framework's registry keeps its guard private, so this
     * asks the same shared guard under the same toggle
     * (`cbox-id.external_actions.verify_url`).
     *
     * @throws ActionRefused
     */
    public static function assertHook(string $url, string $field = 'url'): void
    {
        IntegrationReach::assertUrl($url, $field);

        if (! UrlVerification::enforced('cbox-id.external_actions.verify_url')) {
            return;
        }

        try {
            app(UrlGuard::class)->assertSafe($url);
        } catch (BlockedUrl) {
            throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public HTTPS endpoint.', $field);
        }
    }

    /**
     * A log stream's collector endpoint ({@see SafeStreamUrl}).
     *
     * @throws ActionRefused
     */
    public static function assertLogStream(string $url, string $field = 'endpoint_url'): void
    {
        IntegrationReach::assertUrl($url, $field);

        try {
            SafeStreamUrl::assert($url);
        } catch (UnsafeStreamUrl) {
            throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public endpoint.', $field);
        }
    }

    /**
     * A SCIM provisioning target's base URL ({@see SafeScimUrl}).
     *
     * @throws ActionRefused
     */
    public static function assertScim(string $url, string $field = 'base_url'): void
    {
        IntegrationReach::assertUrl($url, $field);

        try {
            SafeScimUrl::assert($url);
        } catch (UnsafeScimUrl $e) {
            throw ActionRefused::because('unsafe_url', 'The SCIM base URL must be a public address. ('.$e->getMessage().')', $field);
        }
    }

    /**
     * An identity provider's published address — a SAML metadata URL, an OIDC issuer
     * ({@see SafeFederationUrl}). The guard only: whether the document there parses is
     * the fetch's to say, which waits for the approval. `$message` wraps the guard's own
     * reason (`%s`), so the refusal reads as the action's does after a fetch.
     *
     * @throws ActionRefused
     */
    public static function assertFederation(string $url, string $code, string $field, string $message = '%s'): void
    {
        try {
            SafeFederationUrl::assert($url);
        } catch (UnsafeFederationUrl $e) {
            throw ActionRefused::because($code, sprintf($message, $e->getMessage()), $field);
        }
    }
}
