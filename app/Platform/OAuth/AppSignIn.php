<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

/**
 * The app a browser is signing in to: what the hosted pages may say about it.
 *
 * Read from the authorization request that sent the person here ({@see AppSignIns}), or
 * from the app a mailed link names when it is opened in another browser. Everything in it
 * was validated by `/oauth/authorize` or comes from the app's own registration; nothing
 * here is taken from a query string on the page that shows it.
 */
final readonly class AppSignIn
{
    public function __construct(
        public string $clientId,
        public string $clientName,
        /** The address to resume the authorization at, when one is still waiting in this browser. */
        public ?string $resumeUrl = null,
        /** Where the app lives, for "start over" and "continue to": its launch address, else its own origin. */
        public ?string $homeUrl = null,
        /** The address the app asked to have filled in (`login_hint`), when it is one. */
        public ?string $loginHint = null,
        public ?string $iconUrl = null,
        public ?string $iconDarkUrl = null,
    ) {}

    /**
     * What a page draws: the name and the app's own icon, never an address to act on.
     *
     * @return array{name: string, iconUrl: string|null, iconDarkUrl: string|null}
     */
    public function props(): array
    {
        return ['name' => $this->clientName, 'iconUrl' => $this->iconUrl, 'iconDarkUrl' => $this->iconDarkUrl];
    }
}
