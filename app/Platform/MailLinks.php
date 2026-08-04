<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\SetEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Routing\UrlGenerator;
use Tests\Feature\MailLinkHostPoisoningTest;

/**
 * Every absolute URL that leaves this deployment inside an EMAIL is minted here.
 *
 * WHY THIS EXISTS. `route()` and `URL::temporarySignedRoute()` build their origin from
 * `$request->getHost()` — the Host header, which the client sends. That is fine for a
 * link rendered back into the page that asked for it (the browser already believes it is
 * on that host), and it is a credential-delivery bug for a link put in an inbox: the
 * recipient's click is the attacker's payload, not the sender's. `Host: evil.example` on
 * `POST /workspace/forgot-password` mailed a working reset link on `evil.example`, and
 * the magic-link and invitation flows are worse still — those carry a BEARER token in the
 * path, so one click hands the token over with no replay trick at all.
 *
 * The account plane is where this bites, because {@see SetEnvironment}
 * deliberately sends an UNMAPPED host to the platform root rather than refusing it (host
 * hardening is the ingress's job — see the note there), so an arbitrary Host still renders
 * the account-plane surfaces. `TrustHosts` (bootstrap/app.php) is the primary fix and this
 * is the second layer: the ingress is per-deployment configuration, and a misconfigured
 * one must not be able to reach an email BODY.
 *
 * ONE helper rather than a fix per call site, deliberately. Eight places mailed a URL and
 * each built its own; that is precisely why this bug class recurs — the ninth would have
 * built its own too. A mailed link is now something you ask this for.
 *
 * @see MailLinkHostPoisoningTest
 */
final class MailLinks
{
    public function __construct(
        private readonly EnvironmentResolver $environments,
        private readonly UrlGenerator $url,
    ) {}

    /**
     * A named route as an absolute URL fit to mail.
     *
     * @param  array<string, mixed>|string  $parameters
     */
    public function route(string $name, array|string $parameters = []): string
    {
        return $this->fromCanonicalRoot(fn (): string => $this->url->route($name, $parameters));
    }

    /**
     * A signed, expiring named route as an absolute URL fit to mail.
     *
     * The forced root is what makes the SIGNATURE the second half of the defence rather
     * than a formality: the signature is computed over this origin, and Laravel's `signed`
     * middleware recomputes it over `$request->url()` — the host the replay arrives on. So
     * a link minted for the real host and replayed with a poisoned one no longer
     * validates, which is exactly how the proven chain was completed.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function temporarySignedRoute(
        string $name,
        DateTimeInterface|DateInterval|int $expiration,
        array $parameters = [],
    ): string {
        return $this->fromCanonicalRoot(
            fn (): string => $this->url->temporarySignedRoute($name, $expiration, $parameters),
        );
    }

    /**
     * Mint inside a forced root URL, and put the generator back afterwards.
     *
     * `finally`, not a trailing reset: the generator is a singleton for the life of the
     * request (and of the worker, under Octane), so an exception thrown mid-mint would
     * otherwise leave every later URL on this request pinned to the forced origin.
     *
     * @param  Closure(): string  $mint
     */
    private function fromCanonicalRoot(Closure $mint): string
    {
        $root = $this->canonicalRoot();

        if ($root === null) {
            return $mint();
        }

        $this->url->forceRootUrl($root);
        $this->url->forceScheme(str_starts_with($root, 'http://') ? 'http' : 'https');

        try {
            return $mint();
        } finally {
            $this->url->forceRootUrl(null);
            $this->url->forceScheme(null);
        }
    }

    /**
     * The origin a mailed link must carry, or null to let the request's own host stand.
     *
     * Null when the request host RESOLVED to an environment. That is the whole test: a
     * host that resolves is one this deployment was configured to answer on — a tenant's
     * custom domain or its `{slug}.{base_domain}` subdomain — and mailing a tenant's
     * users a link on the platform apex instead of on their own IdP host would be a
     * regression, not a fix. A host that resolves to nothing is the poisoning case, and
     * `app.url` is the only origin this deployment states about ITSELF.
     */
    private function canonicalRoot(): ?string
    {
        if ($this->environments->resolveForHost($this->url->getRequest()->getHost()) !== null) {
            return null;
        }

        $configured = config('app.url');

        if (! is_string($configured)) {
            return null;
        }

        $configured = rtrim(trim($configured), '/');

        // An `app.url` with no host is not an origin — falling back to it would produce a
        // relative link in an email, which is worse than the poisoned one it replaced.
        return $configured !== '' && parse_url($configured, PHP_URL_HOST) !== null
            ? $configured
            : null;
    }
}
