<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RevokeTokensOnRoleChange;
use App\Platform\Appearance\BrandContext;
use App\Platform\BreachedPasswords;
use App\Platform\Console\DashboardCards;
use App\Platform\CurrentEnvironment;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\FrontendApi\AppearanceConfig;
use App\Platform\ImpersonationAwareAuditLog;
use App\Platform\Install\Contracts\PlatformInstaller;
use App\Platform\Install\Contracts\SetupTokens;
use App\Platform\Install\DatabasePlatformInstaller;
use App\Platform\Install\EnvFile;
use App\Platform\Install\FileSetupTokens;
use App\Platform\OpenEntitlements;
use App\Platform\PlatformSignedInSubject;
use App\Platform\RevokingAuthPolicies;
use App\Platform\TrustedHosts;
use Cbox\Id\FrontendApi\Contracts\FrontendConfigContributor;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\SignedInSubject;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Authorization\CachedEntitlements;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementReader;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Migration\Contracts\LegacyCredentialSource;
use Cbox\Id\Migration\Sources\DeclaredCredentialSource;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request: the authenticated subject + org context.
        $this->app->scoped(CurrentUser::class);

        // WHO IS SIGNED IN, for the package's own logout endpoints. Its default reads
        // Laravel's guard, which this application never populates — see
        // PlatformSignedInSubject for what that silently cost RP-initiated logout.
        $this->app->scoped(SignedInSubject::class, PlatformSignedInSubject::class);

        // The customer's theme, on the Frontend API's public config document. Tagged
        // rather than referenced by the package: the framework owns the channel and
        // deliberately does not know what branding is. Without it a page can learn where
        // to POST and then renders the form in our colours, which is the tell that gives
        // away an embedded widget as somebody else's.
        $this->app->tag(AppearanceConfig::class, FrontendConfigContributor::class);

        // THE LEGACY LOGIN AN APP DECLARED, once a person has approved it. Bound
        // unconditionally because the source itself is inert without an approved row —
        // it answers null to everything, exactly as if nothing were configured — so
        // there is no state where binding it does something an operator did not ask for.
        //
        // Without this binding the whole feature is decorative: an operator could approve
        // a declaration and nothing would ever consult it.
        /*
         * ONE REGISTRY PER REQUEST. Every module pushes its dashboard card into this during
         * boot; resolved fresh each time, each provider would fill its own copy and the page
         * would read an empty one.
         */
        $this->app->singleton(DashboardCards::class);

        $this->app->singleton(LegacyCredentialSource::class, DeclaredCredentialSource::class);

        // Read once per request: three view components label themselves with the current
        // environment, and one of them renders per deletable row.
        $this->app->scoped(CurrentEnvironment::class);

        /*
         * WHICH BRAND THIS REQUEST IS PAINTED IN — resolved once per request, and it has
         * to be `scoped` rather than transient because it is WRITTEN in one place and READ
         * in two others.
         *
         * A branded sign-in controller pins the organization; the root view emits its token
         * override into `<head>` before React exists, and the Inertia middleware shares the
         * name and logo as props. With a fresh instance per resolution the controller pins
         * one object and the other two read empty ones — which renders the platform's own
         * colours on a page whose whole purpose is to wear the customer's.
         */
        $this->app->scoped(BrandContext::class);

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
            // Opt-in, because the token is the entire authority to claim an unclaimed
            // platform and a centralised log aggregator is not a secret store. See
            // FileSetupTokens::issue().
            (bool) config('cbox-id.log_setup_token'),
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

        /*
         * TWO LIVEWIRE REGISTRATIONS USED TO LIVE HERE, and both are gone with it.
         *
         * The first was the PERSISTENT-MIDDLEWARE list. Livewire re-ran only the middleware
         * named there on `POST /livewire/update`, so every route-level guard in the app had
         * to be repeated in one place — and a guard left out of it enforced on the first
         * page load and then silently stopped. That is not a hazard a controller has: a
         * ported page's every interaction is its own request through its own stack, so the
         * stack IS the answer and there is no second list to keep in step with it.
         *
         * The second was a `call`-seam guard that made impersonation read-only. Route
         * middleware could not see individual component actions — all of them POSTed to the
         * one endpoint — so the guard hung off the seam and refused by method NAME, against
         * an allowlist of read primitives. {@see \App\Http\Middleware\ReadOnlyWhileImpersonating}
         * refuses on the HTTP VERB instead, which is deny-by-default rather than a list:
         * a write added tomorrow is refused without anybody remembering it exists.
         */
    }
}
