<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateEnvironmentAdmin;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnforceImpersonationWindow;
use App\Http\Middleware\EnforcePlane;
use App\Http\Middleware\PointAtFirstRun;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireEnvironmentSudo;
use App\Http\Middleware\RequireMultiTenant;
use App\Http\Middleware\RequireSudo;
use App\Http\Middleware\TargetEnvironment;
use App\Listeners\RevokeTokensOnRoleChange;
use App\Platform\AccountAuth;
use App\Platform\BreachedPasswords;
use App\Platform\CurrentEnvironment;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\ImpersonationAwareAuditLog;
use App\Platform\ImpersonationCallGuard;
use App\Platform\Install\Contracts\PlatformInstaller;
use App\Platform\Install\Contracts\SetupTokens;
use App\Platform\Install\DatabasePlatformInstaller;
use App\Platform\Install\EnvFile;
use App\Platform\Install\FileSetupTokens;
use App\Platform\OpenEntitlements;
use App\Platform\RevokingAuthPolicies;
use App\Platform\TrustedHosts;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Authorization\CachedEntitlements;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementReader;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Psr\Log\LoggerInterface;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request: the authenticated subject + org context.
        $this->app->scoped(CurrentUser::class);

        // Read once per request: three view components label themselves with the current
        // environment, and one of them renders per deletable row.
        $this->app->scoped(CurrentEnvironment::class);

        // One instance per request: the environment-admin session resolver. Consulted
        // by the persistent middleware, the layout, and each component boot() — scoping
        // it lets current() memoise instead of re-running ~3 identity queries per call.
        $this->app->scoped(EnvironmentAdminAuth::class);

        // …and its sibling for account membership, which was left out of that refactor. It
        // is asked by ConsoleScope, the rail's feature gates and every Identity platform
        // page's own guard, and each ask crosses into the platform root for the member row
        // and its account: measured at four resolutions on the projects page and nine on
        // the activity one. Without the `scoped` binding the memo it now carries would
        // never be hit, because every app() call would build a new object.
        $this->app->scoped(AccountAuth::class);

        // Replace the framework's deliberately-inert breach check with the real HIBP
        // k-anonymity lookup, so the tenant password policy's requireBreachCheck
        // actually consults a breach corpus rather than silently passing everything.
        $this->app->singleton(BreachedPasswordCheck::class, BreachedPasswords::class);

        // Dual-attribution audit for privileged impersonation: wrap the framework
        // audit logger so EVERY recorded event (framework-emitted included) carries
        // the acting operator in its context while a marker is active. Decorating
        // the existing binding means nothing can bypass it.
        $this->app->extend(AuditLog::class, function (AuditLog $inner): AuditLog {
            return new ImpersonationAwareAuditLog($inner);
        });

        // A tightened SSO mandate ends the sessions that predate it. Decorating the
        // CONTRACT rather than the console page that writes it: the mandate is the one
        // policy rule no live session is ever re-asked (see {@see RevokingAuthPolicies}),
        // and the policy that governs an ACCOUNT member's password — their organization's
        // override — has no write surface at all yet, so a rule bolted onto today's
        // writers would miss the population it exists for. Extend, not bind: the framework
        // resolves the concrete implementation with its per-request memo, and this wraps
        // whatever it registered rather than replacing it.
        $this->app->extend(AuthPolicies::class, fn (AuthPolicies $inner, Application $app): AuthPolicies => new RevokingAuthPolicies(
            $inner,
            $app->make(SessionManager::class),
            $app->make(Memberships::class),
        ));

        // Entitlements: grant-by-default unless this deployment is metered.
        //
        // A fresh BIND rather than extend(): the framework registers
        // EntitlementReader and EntitlementWriter as two ALIASES of the same
        // CachedEntitlements singleton, and extend() resolves aliases — so
        // extending the reader would hand the writer an OpenEntitlements that does
        // not implement EntitlementWriter, and every write would fail. Binding the
        // reader abstract on its own leaves the writer alias pointing at the real
        // implementation, which is what we want: writes must always reach the
        // projection, only reads get a floor.
        $this->app->singleton(EntitlementReader::class, fn (Application $app): EntitlementReader => new OpenEntitlements(
            $app->make(CachedEntitlements::class),
            $app->make('config'),
        ));

        // Bootstrapping an empty deployment. Scoped rather than singleton: emptiness is
        // a fact about the database, and the middleware, the first-run screen and the
        // install command must all see the same answer WITHIN a request — but a
        // long-lived worker must not carry "this platform is empty" across the request
        // that stopped it being true.
        $this->app->scoped(PlatformInstaller::class, DatabasePlatformInstaller::class);

        // The setup token lives on the LOCAL disk explicitly, not on the default one: a
        // deployment that points `FILESYSTEM_DISK` at S3 would otherwise publish its
        // first-run secret to object storage, where "only console access can read it"
        // stops being true.
        $this->app->singleton(SetupTokens::class, fn (Application $app): SetupTokens => new FileSetupTokens(
            $app->make(FilesystemFactory::class)->disk('local'),
            $app->make(LoggerInterface::class),
        ));

        // The env file this deployment actually booted from (`.env.production`, a
        // per-environment file, …) rather than a hard-coded `.env` — the installer
        // records the tenancy shape there, and writing it to a file nothing reads
        // would leave the deployment provisioned one way and configured another.
        $this->app->singleton(EnvFile::class, fn (): EnvFile => new EnvFile(app()->environmentFilePath()));
    }

    public function boot(): void
    {
        // Every view can read `$me` without each component wiring it up.
        View::share('me', $this->app->make(CurrentUser::class));

        // RBAC freshness: revoke a user's refresh tokens when their roles change, so a
        // grant/downgrade takes effect on next refresh rather than riding a stale token.
        Event::listen(EventDelivered::class, RevokeTokensOnRoleChange::class);

        // The trusted-Host allow-list is derived from the `environments` table and cached
        // for the resolution TTL, and NOTHING invalidated it. The window that opened is
        // narrow and total: the instant a tenant's custom domain verifies, that host is a
        // real host serving a real environment — and `TrustHosts` runs ahead of routing,
        // so until the entry lapsed every request to it answered 400. A cleared domain had
        // the mirror problem, staying trusted after it stopped being ours.
        //
        // On the model rather than in the domain service, matching what the framework does
        // for the resolution cache: every write that can change which hosts we answer on
        // is a save or a delete of this row, so this is the one place a new call site
        // cannot route around. `saved` covers the verification stamp, the clear, and the
        // slug rename that moves a `{slug}.{base}` host.
        Environment::saved(static function (): void {
            TrustedHosts::forget();
        });

        Environment::deleted(static function (): void {
            TrustedHosts::forget();
        });

        // Livewire only re-runs *persistent* middleware on /livewire/update, so the
        // route-level auth guards must be registered here — in source, not via a
        // vendored edit that `composer install` would silently revert. Without this
        // the org console loses CurrentUser on every action, and a suspended
        // operator keeps full powers because AuthenticateOperator never re-checks.
        // EVERY route-level guard belongs here. A guard that is absent enforces on the
        // first page load and then silently stops: the component's actions all POST to
        // /livewire/update, where only this list re-runs. PersistentMiddlewareTest holds
        // the invariant — it walks the real route table and fails on any app middleware
        // guarding a web route that is missing here.
        Livewire::addPersistentMiddleware([
            // Ahead of Authenticate: a Livewire action on an impersonated page must
            // also self-terminate once the time-box lapses, not just full loads.
            EnforceImpersonationWindow::class,
            Authenticate::class,
            AuthenticateOperator::class,
            // …and, once the authority holds, the plane the operator aimed the console
            // at. A Livewire action on an operator page reads the same tenant data the
            // page did, so it has to run in the same environment — without this the
            // action runs in the HOST's environment and answers about a different plane
            // than the one on screen. Listed AFTER the gate for the reason the route
            // group orders them that way: the selection must not be ambient while the
            // operator's own platform-root session is being resolved.
            TargetEnvironment::class,
            RedirectIfAuthenticated::class,
            // The guest Admin Portal setup screen is Livewire too — keep its
            // scoped-session guard on every /livewire/update, not just first load.
            PortalSession::class,
            // The environment control plane is a Livewire console: without this, its
            // actions answered unauthenticated. The snapshot checksum is keyed on APP_KEY —
            // identical on every tenant host — so a snapshot captured in one tenant could
            // be replayed against another tenant's host. The account pages that used to
            // need their own gate here are pages of the one console now, behind the
            // subject session `platform.auth` already persists.
            AuthenticateEnvironmentAdmin::class,
            // Plane bulkheads and the step-up gate must hold per action too, or a retained
            // snapshot bypasses sudo permanently once confirmed.
            EnforcePlane::class,
            // "Does this surface exist on this deployment?" is the same kind of question as
            // "is this the right plane?", and it has to be re-asked per action for the same
            // reason: the snapshot checksum is keyed on APP_KEY, so a snapshot of an
            // environment-console component captured on a multi-tenant install can be POSTed
            // to /livewire/update on a single-tenant one, where the page it came from 404s.
            // The env-admin session gate refuses that too — this keeps the two independent.
            RequireMultiTenant::class,
            RequireSudo::class,
            RequireEnvironmentSudo::class,
            // Keeps the "an impersonator cannot plant persistence" property true for
            // component actions, not just full page loads.
            BlockDuringImpersonation::class,
            // The first-run bulkhead. It runs in the global `web` group, so it already
            // covers /livewire/update — but it is the gate that decides whether the
            // screen which provisions the platform root exists at all, and this list is
            // where that kind of decision is stated rather than inferred from grouping.
            PointAtFirstRun::class,
        ]);

        // Make impersonation effectively READ-ONLY across the whole console. Route
        // middleware can't see individual Livewire actions (all POSTed to one
        // /livewire/update endpoint), so guard the `call` seam itself: while
        // impersonating, every component action is refused (403) except a tight
        // allowlist of read/navigation primitives. Deny-by-default — a new mutating
        // action is blocked with no extra wiring, so no sink can be missed.
        Livewire::listen('call', function (mixed $component, string $method): void {
            app(ImpersonationCallGuard::class)->guard($method);
        });
    }
}
