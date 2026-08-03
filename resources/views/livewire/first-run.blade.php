<?php

declare(strict_types=1);

use App\Http\Middleware\PointAtFirstRun;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\Install\Contracts\PlatformInstaller;
use App\Platform\Install\Contracts\SetupTokens;
use App\Platform\Install\Enums\DeploymentShape;
use App\Platform\Install\InstalledPlatform;
use App\Platform\Install\InstallPlan;
use App\Platform\Install\OperatorIdentity;
use App\Platform\PlaneResolver;
use App\Platform\PlatformAuth;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * First run — the only door into an unclaimed deployment.
 *
 * This is the safe version of "lazy-create the first tenant on the first request". Plain
 * lazy-create hands a public identity provider to whoever reaches it first, and on the
 * open internet that is a scanner rather than the operator. So the screen exists ONLY
 * while the platform is empty ({@see PointAtFirstRun} 404s it the moment it is not), and
 * it demands the setup token, which is published where only filesystem or console access
 * can read it — never rendered here, never in the URL.
 *
 * It does NOT choose the deployment shape. A web request cannot durably write `.env`
 * (the file may be read-only, the config may be cached, and a horizontally-scaled
 * deployment would only have written it on one node), so a screen that offered
 * multi-tenancy would leave the platform provisioned one way and configured another.
 * It installs the shape this deployment is already configured for, and refuses outright
 * when that configuration is incoherent — `php artisan cbox-id:install` is where the
 * shape is decided, because it is the caller that can write the answer down.
 */
new #[Layout('components.layouts.auth', ['title' => 'Set up Cbox ID'])] class extends Component
{
    public string $token = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $environmentName = 'Production';

    public string $accountName = '';

    /** The configured shape, shown so the operator sees what they are about to create. */
    public bool $multiTenant = false;

    /** Multi-tenant with nowhere for the account console to live — nothing safe to install. */
    public bool $misconfigured = false;

    /** The database is reachable but has no schema — this deployment has not migrated. */
    public bool $unmigrated = false;

    public function mount(PlatformInstaller $installer, SetupTokens $tokens, PlaneResolver $planes): void
    {
        abort_unless($installer->isEmpty(), 404);

        // Arming happens on the first LOOK, not on the first submission: the operator
        // needs the token before they can fill anything in. A scanner triggers the same
        // mint and learns nothing — the value goes to the log and the private disk.
        $tokens->issue();

        $this->multiTenant = $planes->isMultiTenant();
        $this->misconfigured = $planes->misconfigured();

        // An un-migrated deployment is empty, so it lands here — and it is the most
        // likely way to arrive, because a container that has never run its migrations is
        // exactly the box nobody has installed yet. Offering the form would mean a
        // submission that dies on a missing table; saying so costs one question.
        $this->unmigrated = ! $installer->ready();
    }

    public function claim(
        PlatformInstaller $installer,
        SetupTokens $tokens,
        PlaneResolver $planes,
        PlatformAuth $auth,
        PlatformRoot $platformRoot,
    ): mixed {
        // Re-asked on the action, not inherited from the render: a Livewire action is its
        // own request, and everything this component is allowed to do rests on these two.
        abort_unless($installer->isEmpty(), 404);
        abort_if($planes->misconfigured() || ! $installer->ready(), 409);

        $this->validate([
            'token' => 'required|string',
            'name' => 'required|string|max:190',
            'email' => 'required|email|max:190',
            'password' => 'required|string|min:12',
            'environmentName' => 'required|string|max:190',
            'accountName' => ($planes->isMultiTenant() ? 'required' : 'nullable').'|string|max:190',
        ]);

        // The token is 32 random bytes, so this is not a brute-force bound — it is a
        // bound on how loudly someone may guess at it, and it keeps an unclaimed
        // deployment from being a free CPU-burning oracle.
        $key = 'first-run|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('token', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return null;
        }

        if (! $tokens->matches($this->token)) {
            RateLimiter::hit($key, 60);
            $this->addError('token', 'That setup token does not match the one this deployment published.');

            return null;
        }

        RateLimiter::clear($key);

        // Serialize the claim so two concurrent submissions cannot both pass the
        // emptiness check and each provision a platform root. The installer re-checks
        // inside, but the window between check and write is exactly what this closes.
        $lock = Cache::lock('cbox:first-run', 10);
        abort_unless($lock->get() === true, 429);

        try {
            $installed = $installer->install(new InstallPlan(
                shape: $planes->isMultiTenant() ? DeploymentShape::MultiTenant : DeploymentShape::SingleTenant,
                operator: new OperatorIdentity($this->email, $this->name, $this->password),
                environmentName: $this->environmentName,
                accountName: $this->accountName !== '' ? $this->accountName : $this->name,
            ));
        } finally {
            $lock->release();
        }

        // Spent, not merely superseded. The route dies with the emptiness check above,
        // but a token left on disk is a live secret for a door that no longer exists.
        $tokens->forget();

        // Signed in through the ORDINARY path, with the password they just chose — the
        // setup token is authority to install, never a credential, so it does not become
        // a session. If what the installer created cannot authenticate, the operator
        // finds out here rather than at their next sign-in.
        //
        // That path is the subject one: an operator is a subject, and the second
        // credential store they used to authenticate against is gone. Run in the platform
        // root's scope, because that is where the installer just wrote them and where
        // their session has to live — subjects and sessions are environment-owned, and the
        // ambient environment on this request is still the pre-install bootstrap default.
        $signedIn = $platformRoot->run(
            fn (): bool => $auth->attemptPassword(request(), $this->email, $this->password) === AttemptOutcome::Ok,
        ) === true;

        // Anything short of a full session — a second factor, a policy hold, or a
        // deployment whose operators are not subjects yet — goes to the sign-in door
        // rather than to a console that would bounce them back to it anyway.
        return redirect()->to($signedIn
            ? route('platform.environments')
            : $this->signIn($installed, $planes));
    }

    /**
     * Where the person who just installed this deployment signs in.
     *
     * In the SaaS shape the door lives on the account host and nowhere else — the
     * bulkheads see to that — so a relative redirect would land on whichever host the
     * install happened to be performed against and 404 there.
     */
    private function signIn(InstalledPlatform $installed, PlaneResolver $planes): string
    {
        $host = $planes->accountHost();

        if (! $installed->shape->isMultiTenant() || $host === null || $host === request()->getHost()) {
            return route('workspace.login');
        }

        return request()->getScheme().'://'.$host.route('workspace.login', absolute: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Set up Cbox ID</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">
        This deployment is empty. Claim it once, from the machine that runs it.
    </p>

    @if ($unmigrated)
        <div class="mt-6 rounded-lg p-4 text-sm" style="background:var(--surface-2);color:var(--muted)">
            <p style="color:var(--text)"><strong>This deployment's database has no schema yet.</strong></p>
            <p class="mt-2">
                Run <code>php artisan migrate --force</code> on the server (or
                <code>php artisan cbox-id:install</code>, which migrates and installs in one step), then reload
                this page.
            </p>
        </div>
    @elseif ($misconfigured)
        <div class="mt-6 rounded-lg p-4 text-sm" style="background:var(--surface-2);color:var(--muted)">
            <p style="color:var(--text)"><strong>This deployment is configured as multi-tenant but has no account host.</strong></p>
            <p class="mt-2">
                Set <code>CBOX_ID_ACCOUNT_HOST</code> (where the account console lives), or set
                <code>CBOX_ID_MULTI_TENANT=false</code> for a single-host install — then reload this page. You can
                also run <code>php artisan cbox-id:install</code>, which asks for both and writes them for you.
            </p>
        </div>
    @else
        <div class="mt-6 rounded-lg p-4 text-sm" style="background:var(--surface-2);color:var(--muted)">
            <p style="color:var(--text)"><strong>Where is the setup token?</strong></p>
            <p class="mt-2">
                In <code>storage/app/private/cbox-id-first-run.token</code> on the server, and in the application
                log (<code>docker logs</code> for a container). It is never shown on this page.
            </p>
        </div>

        <form wire:submit="claim" class="mt-7 space-y-4">
            <div>
                <label class="label" for="token">Setup token</label>
                <input wire:model="token" id="token" type="password" autocomplete="off" class="input input-lg" placeholder="Paste the token from the server" autofocus>
                @error('token') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="name">Your name</label>
                <input wire:model="name" id="name" type="text" class="input input-lg" placeholder="Root Operator">
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="email">Your email</label>
                <input wire:model="email" id="email" type="email" autocomplete="username" class="input input-lg" placeholder="operator@yourco.example">
                @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="password">Password</label>
                <input wire:model="password" id="password" type="password" autocomplete="new-password" class="input input-lg" placeholder="At least 12 characters">
                @error('password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="environmentName">Name your first environment</label>
                <input wire:model="environmentName" id="environmentName" type="text" class="input input-lg" placeholder="Production">
                <p class="mt-1 text-xs" style="color:var(--faint)">
                    An environment is the hard isolation boundary — its own users, keys and issuer.
                </p>
                @error('environmentName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            @if ($multiTenant)
                <div>
                    <label class="label" for="accountName">Account name</label>
                    <input wire:model="accountName" id="accountName" type="text" class="input input-lg" placeholder="Your company">
                    <p class="mt-1 text-xs" style="color:var(--faint)">
                        This deployment is configured as multi-tenant, so the install also creates the first account —
                        the workspace that owns environments and billing.
                    </p>
                    @error('accountName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled">
                Install this deployment
            </button>
        </form>
    @endif

    <p class="mt-6 text-xs" style="color:var(--faint)">
        Prefer the command line? <code>php artisan cbox-id:install</code> does the same thing, and is the only path
        that can also choose and record the deployment shape.
    </p>
</div>
