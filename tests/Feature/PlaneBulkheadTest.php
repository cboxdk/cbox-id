<?php

declare(strict_types=1);

use App\Http\Middleware\EnforcePlane;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Build the middleware with a fixed current + default environment (multi-tenant SaaS). */
function planeGate(?string $current, ?string $default, ?string $hostResolves = null): EnforcePlane
{
    // Multi-tenant shape, STATED. It used to be implied by setting `base_domains`, which
    // worked only because nothing else stated the mode; once the flag existed, the
    // ambient value from `.env` decided these tests instead of the line below them.
    config(['cbox-id.tenancy.multi_tenant' => true]);
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    // The platform-root env is resolved via the config default first (like SetEnvironment).
    config(['cbox-id.environments.default' => $default ?? '']);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn($current !== null ? GenericEnvironment::of($current) : null);
    // The first-party gate names the environment it reads rather than inheriting one, so
    // it lifts the deny-by-default scope for that one query. DELEGATED TO THE REAL
    // CONTEXT rather than run straight: this mock stands in for host resolution, and
    // lifting the scope is the database's business, not host resolution's. A stub that
    // merely invoked the callback left the deny-by-default scope in place, so the gate
    // found no client and the test failed for a reason the production path does not have.
    $realContext = app(EnvironmentContext::class);
    $ctx->shouldReceive('withoutScope')->andReturnUsing(fn (callable $callback) => $realContext->withoutScope($callback));

    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn($default !== null ? GenericEnvironment::of($default) : null);
    // The operator gate asks the HOST, not the context. An unmapped host resolves to
    // null and falls through to the default, exactly as SetEnvironment does.
    $resolver->shouldReceive('resolveForHost')
        ->andReturn($hostResolves !== null ? GenericEnvironment::of($hostResolves) : null);

    return new EnforcePlane(new PlaneResolver($ctx, $resolver));
}

/**
 * The HOST is a parameter, because one of the four planes is a host question.
 *
 * `plane:console` asks {@see PlaneResolver::servesConsole()}, which resolves the request's
 * own Host rather than the environment context — an unmapped name resolves to the platform
 * root, so the context cannot tell `cboxid.com` from `anything.invalid`. A helper that
 * always sent `localhost` would have asserted the console away on every host and called it
 * a passing bulkhead.
 */
function passesPlane(EnforcePlane $gate, string $plane, string $host = 'localhost', ?string $clientId = null): bool
{
    // `client_id` as a query parameter, which is where `/oauth/authorize` carries it.
    // `$request->input()` reads the body too, so the token endpoints' form-encoded
    // parameter reaches the same gate by the same name.
    $uri = 'https://'.$host.'/'.($clientId !== null ? '?client_id='.urlencode($clientId) : '');

    try {
        $gate->handle(Request::create($uri), fn () => new Response('ok'), $plane);

        return true;
    } catch (NotFoundHttpException) {
        return false;
    }
}

/**
 * A client row in the environment the gate will be asked about, returning its client_id.
 *
 * Written straight to the table rather than through the registry service: the two flags
 * being distinguished here are the whole subject of the assertions, and a factory that
 * defaulted either of them would decide the test's outcome instead of the test.
 */
function firstPartyClient(bool $firstParty, ?string $organizationId): string
{
    $clientId = 'cid_'.Str::lower(Str::random(20));

    DB::table('oauth_clients')->insert([
        'id' => (string) Str::ulid(),
        'environment_id' => 'env_prod',
        'client_id' => $clientId,
        'name' => 'Probe '.$clientId,
        'type' => 'public',
        'redirect_uris' => json_encode(['https://example.test/cb']),
        'grant_types' => json_encode(['authorization_code']),
        'scopes' => json_encode(['openid']),
        'first_party' => $firstParty,
        'organization_id' => $organizationId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $clientId;
}

it('serves the account plane ONLY on the platform-root host', function (): void {
    // Root host: current env IS the default (is_default) env.
    $root = planeGate('env_prod', 'env_prod');
    $tenant = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($root, 'account'))->toBeTrue()
        ->and(passesPlane($tenant, 'account'))->toBeFalse(); // never on a tenant host
});

/**
 * The console is served EVERYWHERE, and the platform root is the case worth pinning.
 *
 * It was `plane:subject` — "every host except the root" — so `cboxid.com/login` and
 * `cboxid.com/dashboard` were both 404. The root is a tenant: its subjects sign in and
 * administer their organizations exactly as any other tenant's do. What it withholds is
 * the ISSUER surface, which is now a different question and asserted as one below.
 */
it('serves the console plane on the platform root as well as on a tenant host', function (): void {
    $tenant = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($tenant, 'console'))->toBeTrue();

    // The root, reached by its own name. `hostResolves` is null on purpose — a root
    // environment provisioned without a `domain` row resolves by host to nothing, which is
    // the shape production has, so the apex has to be matched by name (base_domains
    // derives consoleHost() = cboxid.com).
    $root = planeGate('env_prod', 'env_prod');

    expect(passesPlane($root, 'console', 'cboxid.com'))
        ->toBeTrue('the platform root has no sign-in door');
});

/**
 * …but not under any OTHER name pointed at the deployment.
 *
 * `SetEnvironment` answers an unmapped host with the platform root, deliberately, and
 * `TrustHosts` admits every wildcard name under `base_domains`. So the CONTEXT is the root
 * environment for `nope.cboxid.com` exactly as it is for `cboxid.com`, and a console gate
 * that read the context would have served a sign-in form on all of them. Asking the host
 * is what makes the difference, and this is the assertion that proves it was asked.
 */
it('refuses the console plane on a name that is not the platform root', function (): void {
    $unmapped = planeGate('env_prod', 'env_prod');

    expect(passesPlane($unmapped, 'console', 'nope.cboxid.com'))->toBeFalse()
        ->and(passesPlane($unmapped, 'console', 'anything.invalid'))->toBeFalse();
});

/**
 * The half of the old `plane:subject` that still holds: the platform root is an identity
 * provider for nobody, so the issuer surface and the environment-admin door are absent
 * there. Separating the console from these two is the whole of this change.
 */
it('withholds the issuer surface and the environment console from the platform root', function (): void {
    $root = planeGate('env_prod', 'env_prod');
    $tenant = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($root, 'issuer', 'cboxid.com'))->toBeFalse()
        ->and(passesPlane($root, 'environment', 'cboxid.com'))->toBeFalse()
        ->and(passesPlane($tenant, 'issuer'))->toBeTrue()
        ->and(passesPlane($tenant, 'environment'))->toBeTrue();
});

/**
 * The token endpoints, which the platform root serves for the binary we ship and for
 * nothing else.
 *
 * THE 404 THIS PINS WAS LIVE. `.well-known/cbox-authenticator` advertised
 * `issuer: https://cboxid.com` with a real client_id, while `plane:issuer` made every
 * endpoint that document implies absent on that host — so scanning the enrolment QR from
 * `/account/devices` worked on a tenant subdomain and failed on the root. The root has
 * subjects (every customer owner, every operator) and the console offers them trusted
 * devices, so the promise was the truthful half and the wall was the half that had to
 * move — but only this far.
 *
 * The refusals are the point. An organization admin signed in at the root can create
 * OAuth clients from Developers › Apps, and those are environment-owned, so they live in
 * the root environment beside ours. Admitting them would let a customer turn the buyer
 * host into an identity provider for their own app and phish every other customer's owner
 * on the one host they all trust.
 */
it('serves the token endpoints on the platform root for a platform-owned first-party client only', function (): void {
    $root = planeGate('env_prod', 'env_prod');

    $ours = firstPartyClient(firstParty: true, organizationId: null);
    $flaggedButOwned = firstPartyClient(firstParty: true, organizationId: 'org_acme');
    $theirs = firstPartyClient(firstParty: false, organizationId: 'org_acme');

    expect(passesPlane($root, 'first-party', 'cboxid.com', $ours))->toBeTrue()
        // First-party alone is not enough: the flag can sit on a client scoped to one
        // organization, and an org-scoped client on the root is exactly the case being
        // refused. Platform-owned (`organization_id` null) is the second half.
        ->and(passesPlane($root, 'first-party', 'cboxid.com', $flaggedButOwned))->toBeFalse()
        ->and(passesPlane($root, 'first-party', 'cboxid.com', $theirs))->toBeFalse()
        // No client named at all — a bare probe of the endpoint — is refused like any
        // other non-first-party caller rather than falling open.
        ->and(passesPlane($root, 'first-party', 'cboxid.com'))->toBeFalse();
})->group('security');

it('leaves the rest of the issuer surface absent on the platform root', function (): void {
    $root = planeGate('env_prod', 'env_prod');
    $ours = firstPartyClient(firstParty: true, organizationId: null);

    // The carve-out is three endpoints, not a door into the plane. Naming our own client
    // must not buy discovery, JWKS, dynamic registration, SCIM or the SAML bindings — all
    // of which ride `plane:issuer` and stay refused with the very client_id that opens
    // the token endpoints.
    expect(passesPlane($root, 'issuer', 'cboxid.com', $ours))->toBeFalse()
        ->and(passesPlane($root, 'environment', 'cboxid.com', $ours))->toBeFalse();
})->group('security');

it('serves the token endpoints on a tenant host for any client, first-party or not', function (): void {
    $tenant = planeGate('env_tenant_a', 'env_prod');

    // A tenant host IS an issuer, for every client it holds — asking for a first-party
    // flag there would refuse the ordinary third-party flows that are the point of the
    // plane. No client row is created: on this path the question is never asked.
    expect(passesPlane($tenant, 'first-party', 'acme.cboxid.com', 'cid_anything'))->toBeTrue()
        ->and(passesPlane($tenant, 'first-party', 'acme.cboxid.com'))->toBeTrue();
});

it('denies every plane when no environment resolves (deny-by-default)', function (): void {
    $none = planeGate(null, 'env_prod');

    expect(passesPlane($none, 'account'))->toBeFalse()
        ->and(passesPlane($none, 'console'))->toBeFalse()
        ->and(passesPlane($none, 'issuer'))->toBeFalse()
        ->and(passesPlane($none, 'environment'))->toBeFalse();
});

it('rejects an unknown plane name', function (): void {
    expect(passesPlane(planeGate('env_prod', 'env_prod'), 'not-a-plane'))->toBeFalse();
});

/**
 * The staff console is the third interactive plane, and shipped with no bulkhead: the
 * operator sign-in was served on every tenant subdomain and on every customer-controlled
 * brand domain. No privilege followed — the operator session is separate — but a Cbox
 * staff login form on a customer's own domain is a phishing surface. It belongs on the
 * platform root, where account credentials are already entered.
 *
 * This test previously asserted the opposite: that 'operator' was an UNKNOWN plane. It
 * encoded the gap rather than catching it.
 */
it('serves the operator plane on the platform-root host only', function (): void {
    $root = planeGate('env_root', 'env_root', hostResolves: 'env_root');
    $tenant = planeGate('env_tenant', 'env_root', hostResolves: 'env_tenant');

    expect(passesPlane($root, 'operator'))->toBeTrue()
        ->and(passesPlane($tenant, 'operator'))->toBeFalse();
});

/**
 * And it keeps serving it after the operator picks a different environment.
 *
 * `SetEnvironment` hands an authenticated operator their PINNED environment rather than
 * the host-resolved one, deliberately, so the console does not jump planes under them.
 * The gate used to read that same context — so the moment a staff member used the
 * environment switcher in the operator layout, present on every page, the context was no
 * longer the platform root and every `/operator/*` route 404'd. Including
 * `POST /operator/logout` and `GET /operator/login`: the console locked itself and the
 * only way out was clearing the session cookie by hand. Creating an environment and
 * jumping to an organization both pin as well, so those flows were broken end to end.
 */
it('keeps serving the operator plane after the operator switches environment', function (): void {
    // Pinned to a tenant environment, still on the platform-root host.
    $gate = planeGate('env_tenant', 'env_root', hostResolves: 'env_root');

    expect(passesPlane($gate, 'operator'))
        ->toBeTrue('switching environment locked the operator out of the staff console');
});

/**
 * An unmapped Host must not reach the staff console.
 *
 * This case existed, and I rewrote it away while making the gate a host question:
 * `planeGate(null, 'env_root')` became `planeGate(null, null)`, which only denies when
 * there is no default environment at all — a state a provisioned deployment never
 * reaches. Meanwhile the gate fell back to `defaultEnvironment()`, which is the same
 * value `platformRootKey()` resolves through, so it compared a value to its own source
 * and returned true for EVERY unmapped host. The docblock said it failed closed.
 *
 * `curl -H 'Host: anything.invalid'` served the Cbox staff sign-in form, on any name
 * pointed at the deployment and on every wildcard subdomain that is not a tenant — which
 * is the phishing surface the operator bulkhead was added to remove.
 */
it('denies the operator plane when the host resolves to nothing', function (): void {
    // A provisioned deployment: a default environment exists, and the host maps to
    // nothing. That combination is what used to pass.
    expect(passesPlane(planeGate(null, 'env_root'), 'operator'))->toBeFalse();

    // And with no default environment either.
    expect(passesPlane(planeGate(null, null), 'operator'))->toBeFalse();
});

/**
 * The other half of that bulkhead, and the half that shipped broken to production.
 *
 * A root environment provisioned without its own `domain` row resolves by host to
 * nothing, so it reaches the fallback — which asked `platformRootHosts()`, a config key
 * with no env binding that no deployment sets. `consoleHost()`, meanwhile, resolves
 * through a three-step chain that lands in every real shape. The two disagreed, and the
 * operator console is the SAME origin as the account console by design, so:
 *
 *     GET https://cboxid.com/login   200
 *     GET https://cboxid.com/operator/login    404
 *
 * The staff console had no door at all on the live deployment. It went unnoticed because
 * a 404 from a plane bulkhead is indistinguishable from a route that was never there.
 */
it('serves the operator plane on the account host when the root has no domain row', function (): void {
    // base_domains derives consoleHost() = cboxid.com; the host resolves to nothing.
    $gate = planeGate(null, 'env_root');

    $request = Request::create('https://cboxid.com/operator/login');

    $served = true;

    try {
        $gate->handle($request, fn () => new Response('ok'), 'operator');
    } catch (NotFoundHttpException) {
        $served = false;
    }

    expect($served)->toBeTrue('the staff console has no door on the account host');
});

it('does NOT split planes in a single-tenant / self-hosted deployment (no base_domains)', function (): void {
    // Single-tenant: one host serves the whole IdP — every plane is allowed, so the
    // subject console is never 404'd just because the lone env is also the default.
    config(['cbox-id.tenancy.multi_tenant' => false]);
    config(['cbox-id.environments.base_domains' => []]);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn(GenericEnvironment::of('the_only_env'));
    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn(GenericEnvironment::of('the_only_env'));
    $gate = new EnforcePlane(new PlaneResolver($ctx, $resolver));

    expect(passesPlane($gate, 'console'))->toBeTrue()
        ->and(passesPlane($gate, 'issuer'))->toBeTrue()
        ->and(passesPlane($gate, 'environment'))->toBeTrue()
        ->and(passesPlane($gate, 'account'))->toBeTrue();
});

/**
 * The plane names the single-tenant branch admits and the names the multi-tenant match
 * arms answer are ONE list now, so they cannot drift — but a rename is exactly the moment
 * a route can be left holding a name that no longer exists, and a plane gate that admits
 * an unknown name is a gate that is not there.
 */
it('refuses a plane name that no longer exists, in both shapes', function (): void {
    $multi = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($multi, 'subject'))->toBeFalse('`plane:subject` was split; the name must not still admit');

    config(['cbox-id.tenancy.multi_tenant' => false]);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn(GenericEnvironment::of('the_only_env'));
    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn(GenericEnvironment::of('the_only_env'));

    expect(passesPlane(new EnforcePlane(new PlaneResolver($ctx, $resolver)), 'subject'))->toBeFalse();
});

/**
 * Every plane name is carried by at least one route — or is named here as deliberately not.
 *
 * `plane:keys` was on ZERO routes for five days. The middleware knew the name, the
 * resolver had `servesVerificationKeys()` written for it, the docblock explained why the
 * platform root needs it, and the commit that added all three never added the config line
 * that puts it on `/.well-known/jwks.json`. It fell back to `plane:first-party`, which
 * decides by reading a `client_id` a key set does not carry, and the root answered 404 to
 * the keys verifying the tokens it was signing — in production.
 *
 * Nothing could have noticed. The unit tests above ask `EnforcePlane` what it ANSWERS for
 * a plane, which was correct throughout; the request tests ask what a URL returns, and
 * only for URLs somebody thought to list. Neither can see a name that is simply never
 * applied. This asks the routing table instead.
 *
 * A name may legitimately carry nothing — `operator` does, and says so in its own
 * docblock — but that has to be a written decision rather than a gap, which is the
 * difference this test is made of.
 */
it('leaves no plane name applied to nothing', function (): void {
    // Stated in the test, not read from the middleware: a list derived from the same
    // source it is checking would accept any drift as correct.
    $deliberatelyUnrouted = [
        // The staff console became a SECTION of the one console (`/platform`), which asks
        // who you are rather than which host you are on. The name is kept because it is
        // still the answer if a separate operator door is ever served again.
        'operator',
    ];

    $applied = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            // BOTH SPELLINGS. Routes registered in this app use the `plane:` alias;
            // the ones the framework registers come from `cbox-id.api.*` config, which
            // may name either. Matching only the class — which is what `route:list`
            // prints, and so what a first attempt copies — found nothing at all, and the
            // floor below is what said so rather than the sweep passing vacuously.
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'plane:')) {
                $applied[] = Str::after($middleware, 'plane:');
            } elseif (str_starts_with($middleware, EnforcePlane::class.':')) {
                $applied[] = Str::after($middleware, ':');
            }
        }
    }

    $applied = array_values(array_unique($applied));

    // The sweep found the routing table, not an empty one.
    expect(count($applied))->toBeGreaterThan(3, 'the plane sweep found almost nothing — it is looking in the wrong place');

    $known = (new ReflectionClass(EnforcePlane::class))->getConstant('PLANES');

    $orphans = array_values(array_diff($known, $applied, $deliberatelyUnrouted));

    expect($orphans)->toBe([], 'plane names no route applies: '.implode(', ', $orphans));

    // And the other direction: a route naming a plane the middleware does not know falls
    // to `default => false` and 404s the surface outright.
    $unknown = array_values(array_diff($applied, $known));

    expect($unknown)->toBe([], 'routes apply plane names EnforcePlane does not know: '.implode(', ', $unknown));
})->group('security');
