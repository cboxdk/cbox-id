<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\OAuthServer\Models\Client;

/**
 * The first-party app launcher. Lists the trusted Cbox apps (Billing, AI Assistant,
 * Cortex, …) the signed-in user can jump straight into: every first-party OAuth
 * client that is platform-owned or owned by the user's org. Clicking one lands the
 * user in that app already signed in — the app starts SSO, the active Cbox ID
 * session and first-party consent-skip complete it with no prompt.
 *
 * The launch URL is the origin of the client's registered redirect URI, so no extra
 * schema is needed; the app's own entry point kicks off the SSO round-trip.
 */
final class AppLauncher
{
    public function __construct(private readonly CurrentUser $me) {}

    /**
     * @return list<array{name: string, url: string, host: string, initial: string, hue: int}>
     */
    public function apps(): array
    {
        if (! $this->me->check()) {
            return [];
        }

        $organizationId = $this->me->organizationId();

        $clients = Client::query()
            ->where('first_party', true)
            ->where(function ($query) use ($organizationId): void {
                // Platform-owned (shared) apps, plus this org's own first-party apps —
                // the same trust boundary that authorizes silent consent-skip.
                $query->whereNull('organization_id');

                if ($organizationId !== null) {
                    $query->orWhere('organization_id', $organizationId);
                }
            })
            ->orderBy('name')
            ->get();

        $apps = [];

        foreach ($clients as $client) {
            $url = $this->launchUrl($client);

            if ($url === null) {
                continue;
            }

            $apps[] = [
                'name' => $client->name,
                'url' => $url,
                'host' => (string) parse_url($url, PHP_URL_HOST),
                'initial' => mb_strtoupper(mb_substr($client->name, 0, 1)),
                // A stable hue per app name, so each tile keeps a recognisable colour
                // across sessions (Okta/Workspace-style app portal).
                'hue' => (int) (crc32($client->name) % 360),
            ];
        }

        return $apps;
    }

    private function launchUrl(Client $client): ?string
    {
        $uri = $client->redirect_uris[0] ?? null;

        if (! is_string($uri)) {
            return null;
        }

        $parts = parse_url($uri);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $url = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        return $url;
    }
}
