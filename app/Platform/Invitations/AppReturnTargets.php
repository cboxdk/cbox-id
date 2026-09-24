<?php

declare(strict_types=1);

namespace App\Platform\Invitations;

use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\ReturnApp;
use App\Platform\Invitations\ValueObjects\ReturnTarget;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * Where an app may ask for an invitee to be sent after they accept.
 *
 * AN IDP THAT REDIRECTS WHEREVER IT IS TOLD IS A PHISHING KIT with a trusted domain on it —
 * so `return_to` is never taken on its own. It has to come with the app it belongs to
 * (`client_id`), that app has to be one this organization can use (its own, or an
 * environment-wide one), and the address has to sit on an ORIGIN the app already registered
 * as a redirect URI. That is the same trust the authorization endpoint extends to the app,
 * and no more: an app that could receive an authorization code at an origin can receive a
 * person there.
 *
 * ORIGIN, not the exact URI. A redirect URI is a callback (`/auth/callback`); where a person
 * should land after joining is a page (`/welcome`, `/projects/42`). Requiring an exact
 * match would make the feature unusable, and the origin is what the trust is about.
 *
 * Asked twice — when the invitation is sent, so the sender hears about a typo, and again
 * when it is accepted, because an app's registrations can change in the week an invitation
 * is live and a stored URL is not a standing permission.
 */
final readonly class AppReturnTargets
{
    /** Hosts where plain http is a developer's machine rather than a downgrade. */
    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /** Long enough for any real deep link; short enough that nobody stores a payload in it. */
    private const MAX_LENGTH = 2048;

    /**
     * The validated target, or null when the invitation names no app at all.
     *
     * @throws InvitationRefused
     */
    public function resolve(string $organizationId, ?string $clientId, ?string $returnTo): ?ReturnTarget
    {
        $clientId = $clientId === null || trim($clientId) === '' ? null : trim($clientId);
        $returnTo = $returnTo === null || trim($returnTo) === '' ? null : trim($returnTo);

        if ($clientId === null) {
            if ($returnTo !== null) {
                throw InvitationRefused::returnWithoutApp();
            }

            return null;
        }

        $client = $this->usableClient($organizationId, $clientId)
            ?? throw InvitationRefused::unknownApp();

        if ($returnTo === null) {
            return new ReturnTarget($client->client_id, $client->name);
        }

        $origin = self::origin($returnTo) ?? throw InvitationRefused::returnToMalformed();

        if (! in_array($origin, $this->registeredOrigins($client), true)) {
            throw InvitationRefused::returnToNotRegistered($client->name);
        }

        return new ReturnTarget($client->client_id, $client->name, $returnTo);
    }

    /**
     * The same question, answered quietly: for an invitation being ACCEPTED, a target that
     * no longer holds is dropped rather than refused. The person still joins — they land in
     * the console instead of the app, which is a worse landing and not a reason to fail.
     */
    public function revalidate(string $organizationId, ?string $clientId, ?string $returnTo): ?ReturnTarget
    {
        try {
            return $this->resolve($organizationId, $clientId, $returnTo);
        } catch (InvitationRefused) {
            // The app may still be valid when only the address fell away — keep the name.
            try {
                return $this->resolve($organizationId, $clientId, null);
            } catch (InvitationRefused) {
                return null;
            }
        }
    }

    /**
     * The apps an invite form can offer for this organization: the ones it may use that
     * have at least one web origin to return a person to. A CLI or a native app with only a
     * custom-scheme callback has nowhere a browser can land.
     *
     * @return list<ReturnApp>
     */
    public function appsFor(string $organizationId): array
    {
        $apps = [];

        $clients = Client::query()
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->orderBy('name')
            ->get(['client_id', 'name', 'redirect_uris']);

        foreach ($clients as $client) {
            $origins = $this->registeredOrigins($client);

            if ($origins !== []) {
                $apps[] = new ReturnApp($client->client_id, $client->name, $origins);
            }
        }

        return $apps;
    }

    /**
     * The app, IF this organization may use it — its own, or one registered for the whole
     * environment. Bound in the WHERE clause rather than compared afterwards, so a client id
     * belonging to another organization resolves to nothing at all.
     */
    private function usableClient(string $organizationId, string $clientId): ?Client
    {
        return Client::query()
            ->where('client_id', $clientId)
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->first();
    }

    /**
     * @return list<string>
     */
    private function registeredOrigins(Client $client): array
    {
        $origins = [];

        foreach ($client->redirect_uris as $uri) {
            $origin = self::origin($uri);

            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * `scheme://host[:port]`, normalised — or null for anything that is not a plain web
     * address a browser should be sent to.
     *
     * Refused: relative and scheme-relative URLs, anything but https (http only on a
     * loopback host), and credentials in the authority (`https://app.example@evil.example`
     * reads as the first host and goes to the second).
     */
    public static function origin(string $url): ?string
    {
        if (strlen($url) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if ($host === '') {
            return null;
        }

        $loopback = in_array($host, self::LOOPBACK, true);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $loopback)) {
            return null;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = $parts['port'] ?? $defaultPort;

        return $scheme.'://'.$host.($port === $defaultPort ? '' : ':'.$port);
    }
}
