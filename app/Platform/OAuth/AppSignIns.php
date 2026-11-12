<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\IntendedUrl;
use App\Platform\Invitations\AppReturnTargets;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\Request;

/**
 * WHICH APP THIS BROWSER IS SIGNING IN TO, for the hosted pages on the way.
 *
 * `/oauth/authorize` sends a person through sign-in, a forced password change, a second
 * factor, an invitation. Each of those pages used to know only "somebody is signing in":
 * the sign-in page spoke of an identity console, and a page that could not go on had
 * nowhere to send the person but their account. The authorize endpoint notes the app here
 * when it interrupts a request, and those pages read it.
 *
 * Kept in the session beside Laravel's intended URL, never in a query string: a page that
 * named whatever app its address said would be a way to put a trusted name on a phishing
 * link. {@see pending()} answers only while the interrupted request is the one this
 * browser will resume; {@see last()} also answers after it was spent, so a page that
 * finds the request gone can still say which app to start over at.
 *
 * A mailed link (sign-in link, password reset) carries the app's client id under
 * {@see PARAMETER}, so the same can be said when it opens in another browser. That value
 * is public and only ever looked up among this environment's registered apps.
 */
final readonly class AppSignIns
{
    /** The query parameter a mailed link names the app with. */
    public const string PARAMETER = 'app';

    private const string SESSION_KEY = 'cbox.app_sign_in';

    /** The app a mailed link named, for the continue page. */
    private const string CONTINUE_KEY = 'cbox.continue_to';

    /** How long after the request the app is still named on a page that has lost it. */
    private const int REMEMBERED_SECONDS = 3600;

    public function __construct(private AppCatalog $catalog) {}

    /** Note the app an interrupted authorization belongs to, and where it resumes. */
    public function remember(Request $request, PendingAuthorization $authorization, ?string $resumeUrl): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'client' => $authorization->clientId,
            'name' => $authorization->clientName,
            'redirect' => $authorization->redirectUri,
            'hint' => $authorization->loginHint,
            'resume' => $resumeUrl,
            'at' => now()->getTimestamp(),
        ]);
    }

    /**
     * The app whose authorization is waiting to be resumed in this browser, or null.
     *
     * Only while the session's intended URL is still that request: once it has been
     * followed, or replaced by another destination, the sign-in page is no longer on its
     * way to the app and must not say so.
     */
    public function pending(Request $request): ?AppSignIn
    {
        $noted = $this->noted($request);

        if ($noted === null || $noted['resume'] === null) {
            return null;
        }

        $intended = $request->session()->get(IntendedUrl::KEY);

        return is_string($intended) && hash_equals($noted['resume'], $intended) ? $this->describe($noted) : null;
    }

    /** The app this browser last started signing in to, for a page that can no longer finish it. */
    public function last(Request $request): ?AppSignIn
    {
        $noted = $this->noted($request);

        return $noted === null ? null : $this->describe($noted);
    }

    /**
     * The app a client id names in this environment, or null.
     *
     * Only an app somebody registered here on purpose. One that registered itself (RFC
     * 7591, or a metadata document) chose its own name and addresses, and a hosted page
     * that sent people to it would be lending this host's name to a stranger.
     *
     * @param  bool  $environmentWide  only an app of the whole environment, never one
     *                                 organization's own: for an id that arrived in a link
     */
    public function named(mixed $clientId, bool $environmentWide = false): ?AppSignIn
    {
        if (! is_string($clientId) || $clientId === '' || strlen($clientId) > 255) {
            return null;
        }

        $client = Client::query()->where('client_id', $clientId)->first();

        if ($client === null || $client->isDynamicallyRegistered() || ($environmentWide && $client->organization_id !== null)) {
            return null;
        }

        $entry = $this->entryFor($client->client_id);

        return new AppSignIn(
            clientId: $client->client_id,
            clientName: $client->name,
            homeUrl: $this->launchUrl($entry) ?? $this->onlyOrigin($client),
            iconUrl: $this->icons($entry)[0],
            iconDarkUrl: $this->icons($entry)[1],
        );
    }

    /**
     * The address a mailed link carries so the app survives the trip through an inbox:
     * `$url` with the pending app's client id added, or `$url` itself when no app waits.
     */
    public function carry(Request $request, string $url): string
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([self::PARAMETER => $pending->clientId]);
    }

    /**
     * A MAILED LINK OPENED IN ANOTHER BROWSER names its app and holds no request. Note
     * the app in this session and answer the page that sends the person on to it
     * ({@see continuation()}), or null when the id names no environment-wide app with a
     * home we know. The answer is on this host, so it can stand as an intended URL.
     *
     * The id came from a link, so it is only ever looked up among the apps the
     * environment's own administrators registered; and it is kept in the session, so the
     * continue page sends nobody anywhere because of what its own address says.
     */
    public function continueTo(Request $request, mixed $clientId): ?string
    {
        $app = $this->named($clientId, environmentWide: true);

        if ($app?->homeUrl === null) {
            return null;
        }

        $request->session()->put(self::CONTINUE_KEY, $app->clientId);

        return route('app.continue');
    }

    /** The home of the app {@see continueTo()} noted, once; null when there is none. */
    public function continuation(Request $request): ?string
    {
        return $this->named($request->session()->pull(self::CONTINUE_KEY), environmentWide: true)?->homeUrl;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * @return array{client: string, name: string, redirect: string|null, hint: string|null, resume: string|null}|null
     */
    private function noted(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $noted = $request->session()->get(self::SESSION_KEY);

        if (! is_array($noted) || ! is_string($noted['client'] ?? null) || ! is_string($noted['name'] ?? null) || ! is_int($noted['at'] ?? null)) {
            return null;
        }

        $age = now()->getTimestamp() - $noted['at'];

        if ($age < 0 || $age > self::REMEMBERED_SECONDS) {
            return null;
        }

        return [
            'client' => $noted['client'],
            'name' => $noted['name'],
            'redirect' => is_string($noted['redirect'] ?? null) ? $noted['redirect'] : null,
            'hint' => is_string($noted['hint'] ?? null) ? $noted['hint'] : null,
            'resume' => is_string($noted['resume'] ?? null) ? $noted['resume'] : null,
        ];
    }

    /**
     * @param  array{client: string, name: string, redirect: string|null, hint: string|null, resume: string|null}  $noted
     */
    private function describe(array $noted): AppSignIn
    {
        $entry = $this->entryFor($noted['client']);

        return new AppSignIn(
            clientId: $noted['client'],
            clientName: $noted['name'],
            resumeUrl: $noted['resume'],
            // The app's published launch address; else the origin it asked to be sent back
            // to, which `/oauth/authorize` matched against its registration.
            homeUrl: $this->launchUrl($entry) ?? ($noted['redirect'] === null ? null : AppReturnTargets::origin($noted['redirect'])),
            loginHint: $noted['hint'],
            iconUrl: $this->icons($entry)[0],
            iconDarkUrl: $this->icons($entry)[1],
        );
    }

    /** The published catalog app this client belongs to, when the environment lists one. */
    private function entryFor(string $clientId): ?CatalogEntry
    {
        return CatalogEntry::query()->where('enabled', true)->orderBy('position')->get()
            ->first(static fn (CatalogEntry $entry): bool => in_array($clientId, (array) $entry->client_ids, true));
    }

    /** The catalog app's published launch address: where the app starts its own sign-in. */
    private function launchUrl(?CatalogEntry $entry): ?string
    {
        $url = $entry?->getAttribute('launch_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return array{0: string|null, 1: string|null} the light and the dark icon
     */
    private function icons(?CatalogEntry $entry): array
    {
        if ($entry === null) {
            return [null, null];
        }

        $shown = $this->catalog->present($entry);

        return [
            is_string($shown['icon_url'] ?? null) ? $shown['icon_url'] : null,
            is_string($shown['icon_dark_url'] ?? null) ? $shown['icon_dark_url'] : null,
        ];
    }

    /** The one web origin a client registered, or null when it has none or several. */
    private function onlyOrigin(Client $client): ?string
    {
        $origins = [];

        foreach ($client->redirect_uris as $uri) {
            $origin = AppReturnTargets::origin($uri);

            if ($origin !== null) {
                $origins[$origin] = true;
            }
        }

        return count($origins) === 1 ? array_key_first($origins) : null;
    }
}
