<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\Request;

/**
 * WHERE A PERSON CAME FROM, when a console or an app sent them to one of their own pages
 * (`?return_to=`), and so where "Back to …" leads.
 *
 * AN IDENTITY PROVIDER THAT LINKS WHEREVER IT IS TOLD IS A PHISHING KIT with a trusted
 * domain on it. So a return address is only ever offered when it is a well-formed absolute
 * URL on a host this deployment already sends people to: the console's own host, the host
 * the page is on, or a host one of this environment's apps registered a redirect URI on.
 * That is the trust the authorization endpoint extends to an app, and no more. Anything
 * else is dropped without a word, and the page falls back to the console.
 *
 * It is kept in the session once validated, so it survives the person moving between
 * their pages (security, activity, the account chooser) and the POSTs in between.
 */
final readonly class ReturnUrl
{
    public const string PARAMETER = 'return_to';

    private const string SESSION_KEY = 'cbox.return_to';

    /** Long enough for any real deep link; short enough that nobody stores a payload in it. */
    private const int MAX_LENGTH = 2048;

    /** Hosts where plain http is a developer's machine rather than a downgrade. */
    private const array LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    public function __construct(private Surfaces $surfaces) {}

    /**
     * The validated return address a request names, or null.
     *
     * @return array{url: string, host: string, label: string}|null
     */
    public function validated(mixed $candidate, Request $request): ?array
    {
        if (! is_string($candidate) || $candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        // No control characters, no backslashes: browsers read `https:\\evil.example` and
        // `https://app.example\@evil.example` differently from parse_url().
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $candidate) === 1) {
            return null;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $own = array_values(array_filter([strtolower($request->getHost()), $this->surfaces->consoleHost(), $this->surfaces->loginHost()]));

        $plainHttpAllowed = in_array($host, self::LOOPBACK, true)
            // A local install served over http links to its own hosts over http.
            || (in_array($host, $own, true) && $request->getScheme() === 'http');

        if ($scheme !== 'https' && ! ($scheme === 'http' && $plainHttpAllowed)) {
            return null;
        }

        if (in_array($host, $own, true)) {
            return ['url' => $candidate, 'host' => $host, 'label' => $this->ownLabel($host)];
        }

        // Client is environment-scoped, so this is the current realm's registered set.
        foreach (Client::query()->get(['name', 'redirect_uris']) as $client) {
            foreach ($client->redirect_uris as $uri) {
                if (strtolower((string) parse_url($uri, PHP_URL_HOST)) === $host) {
                    return ['url' => $candidate, 'host' => $host, 'label' => $client->name !== '' ? $client->name : $host];
                }
            }
        }

        return null;
    }

    /**
     * Take the address this request names, if it names a valid one, and answer the one in
     * force: this request's, else the one remembered from an earlier page.
     *
     * @return array{url: string, host: string, label: string}|null
     */
    public function remember(Request $request): ?array
    {
        if ($request->query->has(self::PARAMETER)) {
            $named = $this->validated($request->query(self::PARAMETER), $request);

            if ($named !== null) {
                $request->session()->put(self::SESSION_KEY, $named['url']);

                return $named;
            }
        }

        return $this->current($request);
    }

    /**
     * The remembered address, validated again: an app's registrations can change, and a
     * stored URL is not a standing permission.
     *
     * @return array{url: string, host: string, label: string}|null
     */
    public function current(Request $request): ?array
    {
        return $request->hasSession()
            ? $this->validated($request->session()->get(self::SESSION_KEY), $request)
            : null;
    }

    /** The remembered address, once: for the redirect that ends the detour. */
    public function pull(Request $request): ?string
    {
        $current = $this->current($request);

        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return $current['url'] ?? null;
    }

    /** The console's name: the deployment's own. */
    public function ownLabel(?string $host = null): string
    {
        $name = config('cbox-id.branding.name', 'Cbox ID');

        return is_string($name) && $name !== '' ? $name : ($host ?? 'Cbox ID');
    }
}
