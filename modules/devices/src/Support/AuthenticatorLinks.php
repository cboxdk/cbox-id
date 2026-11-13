<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use App\Platform\Surfaces\Surfaces;
use Illuminate\Http\Request;

/**
 * THE HTTPS LINK THAT OPENS THE AUTHENTICATOR ON ONE APPROVAL.
 *
 * A browser cannot tell whether an app is installed, and probing a custom scheme shows
 * an error dialog on iOS when it is not. A Universal Link (iOS) or verified App Link
 * (Android) needs no probe: the system opens the app when the app claims the path, and
 * the browser loads the same URL as a page when it does not.
 *
 * The link carries the approval's opaque id and nothing else. It authorises nothing: the
 * app fetches the request over its own DPoP-bound session, the server answers only the
 * subject it belongs to, and the person compares the six-digit code shown in both places.
 */
final readonly class AuthenticatorLinks
{
    public const string PATH = '/authenticator/approve/';

    public function __construct(private Surfaces $surfaces) {}

    /** Whether any platform's association is configured. */
    public function configured(): bool
    {
        return $this->iosAppIds() !== [] || $this->androidTargets() !== [];
    }

    /**
     * The link for a phone's browser, or null: on a desktop browser, when nothing is
     * configured, or when the only host to point at is the page's own (iOS keeps such a
     * link in Safari, so the button would only ever reload the page).
     */
    public function forApproval(Request $request, string $requestId): ?string
    {
        if (! $this->configured() || ! self::isPhone($request) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $requestId) !== 1) {
            return null;
        }

        $current = mb_strtolower($request->getHost());

        foreach ($this->hosts() as $host) {
            if ($host !== $current) {
                return 'https://'.$host.self::PATH.$requestId;
            }
        }

        return null;
    }

    /**
     * A phone or tablet browser that an installed app could be opened from: the user agent
     * names one, or a Chromium browser says so in its `Sec-CH-UA-Mobile` client hint.
     */
    public static function isPhone(Request $request): bool
    {
        return preg_match('/\b(iPhone|iPad|iPod|Android)\b/', (string) $request->userAgent()) === 1
            || trim((string) $request->header('Sec-CH-UA-Mobile')) === '?1';
    }

    /**
     * The `apple-app-site-association` document, or null when no iOS app is configured.
     *
     * @return array<string, mixed>|null
     */
    public function appleAssociation(): ?array
    {
        $apps = $this->iosAppIds();

        if ($apps === []) {
            return null;
        }

        return [
            'applinks' => [
                'details' => [[
                    'appIDs' => $apps,
                    'components' => [[
                        '/' => self::PATH.'*',
                        'comment' => 'Opens one approval request in the Cbox ID app.',
                    ]],
                ]],
            ],
        ];
    }

    /**
     * The Digital Asset Links statements, or null when no Android app is configured.
     *
     * @return list<array<string, mixed>>|null
     */
    public function androidAssociation(): ?array
    {
        $targets = $this->androidTargets();

        if ($targets === []) {
            return null;
        }

        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => $targets,
        ]];
    }

    /** @return list<string> */
    private function iosAppIds(): array
    {
        return array_values(array_filter(
            DeviceConfig::strings('id-devices.universal_links.ios_app_ids'),
            static fn (string $id): bool => preg_match('/^[A-Z0-9]{10}\.[A-Za-z0-9.-]+$/', $id) === 1,
        ));
    }

    /** @return array{namespace: string, package_name: string, sha256_cert_fingerprints: list<string>}|array{} */
    private function androidTargets(): array
    {
        $package = DeviceConfig::nullableString('id-devices.universal_links.android_package');
        $prints = array_values(array_filter(
            array_map('strtoupper', DeviceConfig::strings('id-devices.universal_links.android_sha256')),
            static fn (string $print): bool => preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $print) === 1,
        ));

        if ($package === null || preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', $package) !== 1 || $prints === []) {
            return [];
        }

        return ['namespace' => 'android_app', 'package_name' => $package, 'sha256_cert_fingerprints' => $prints];
    }

    /** @return list<string> */
    private function hosts(): array
    {
        $hosts = DeviceConfig::strings('id-devices.universal_links.hosts');

        if ($hosts === []) {
            $hosts = array_filter([$this->surfaces->loginHost(), $this->surfaces->consoleHost()], is_string(...));
        }

        return array_values(array_unique(array_map(static fn (string $host): string => mb_strtolower(trim($host)), $hosts)));
    }
}
