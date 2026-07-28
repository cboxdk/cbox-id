<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\OAuthServer\Models\Client;

/**
 * What the authenticator app's OAuth client looks like, in one place.
 *
 * The provisioning command writes it and the bootstrap endpoint reads it back, and the
 * two disagreeing would be a silent, confusing failure — the app would discover a
 * client_id whose scopes or redirect URIs do not match what it is about to ask for.
 */
final class AuthenticatorClient
{
    public const NAME = 'Cbox ID Authenticator';

    /**
     * Read and write are separate so a future read-only surface — a watch complication,
     * a home-screen widget — can display a pending prompt without carrying the authority
     * to answer it. `offline_access` is required or the token endpoint issues no refresh
     * token at all, and the app would silently need a full re-login every 15 minutes.
     *
     * @var list<string>
     */
    public const SCOPES = ['openid', 'offline_access', 'devices.manage', 'approvals.read', 'approvals.write'];

    /**
     * The private-use scheme. Reverse-domain because the platform's own redirect-URI
     * validator requires it (a scheme with no dot is rejected), which is also RFC 8252's
     * advice — an undotted scheme is far easier for another app on the device to squat.
     */
    public const SCHEME = 'dk.cbox.id.authenticator';

    /**
     * Two redirect URIs, and the HTTPS one is preferred.
     *
     * RFC 8252 §7.2 prefers a claimed HTTPS URI precisely because a private-use scheme
     * CAN be registered by a malicious app on the same handset, and the OS gives no
     * guarantee about which one wins. Both are registered so that whichever way the
     * deep-link spike lands, it is a config change rather than a re-registration.
     *
     * @return list<string>
     */
    public static function redirectUris(string $host): array
    {
        $uris = [self::SCHEME.':/oauth/callback'];

        if ($host !== '') {
            array_unshift($uris, 'https://'.$host.'/app/oauth/callback');
        }

        return $uris;
    }

    /**
     * The provisioned client in the current environment, or null.
     *
     * Matched on name: a public client's id is minted per environment and cannot be
     * pinned, so the name is the only stable handle. Scoped like every other read, so
     * this only ever finds the acting environment's own client.
     */
    public static function find(): ?Client
    {
        return Client::query()->where('name', self::NAME)->first();
    }

    public static function defaultEnvironmentId(): string
    {
        return DeviceConfig::string('cbox-id.environments.default');
    }

    /**
     * The host the environment is served on, derived from the app URL. Only a default —
     * a deployment serving several hosts should pass --host explicitly.
     */
    public static function hostFromAppUrl(): string
    {
        $url = DeviceConfig::string('app.url');

        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
