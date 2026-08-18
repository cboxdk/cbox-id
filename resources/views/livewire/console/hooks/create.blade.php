<?php

declare(strict_types=1);

use App\Platform\VerifiedEmailGate;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Inline hooks › New. A dedicated, deep-linkable create page. Registers a
 * customer HTTPS endpoint the platform calls synchronously at a {@see HookPoint};
 * registration mints a signing secret that is returned exactly once, so we hand it to
 * the detail page as a one-time flash and route straight there.
 *
 * One component, both planes. The organization plane registered from an inline form on
 * the list with the organization implied; the environment plane from this page with an
 * organization picker on the form. The picker is gone — the console chrome owns which
 * organization is being administered, and a second copy of that answer on the form is
 * how the two planes came to validate it differently.
 *
 * What the picker also carried was its "All organizations" option, which is NOT an
 * organization: it registers an endpoint the environment itself owns, firing on every
 * organization's sign-ins and token grants. That is a platform capability rather than
 * something a tenant may ask for, so it survives as its own explicit choice, offered and
 * honoured on the environment plane only.
 *
 * The hook point drifted too: the organization console offered exactly one of the six,
 * in the one field that decides which operation your endpoint gets a say in. Both planes
 * now offer the enum, which is the public contract.
 */
new #[Layout('components.layouts.console', ['title' => 'New inline hook'])] class extends Component
{
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    /**
     * Ask for the step-up at the door, before there is a form to lose — see
     * {@see stepUpPending()} for what it guards and why the write asks again.
     */
    public function mount(): void
    {
        $this->stepUpPending();
    }

    public string $hook = 'token_minting';

    public string $url = '';

    /**
     * Register an endpoint the ENVIRONMENT owns rather than one organization's.
     * Offered on the environment plane only — and refused there and then if it arrives
     * from anywhere else, because a Livewire property is client-settable and a control
     * that is merely unrendered is not a control that is enforced.
     */
    public bool $environmentWide = false;

    /**
     * Register the endpoint, reveal its secret once, and route to its detail page.
     *
     * Named for the domain verb the contract uses. The two consoles called this
     * `register` and `create` — one capability under two names, which is how a test
     * exercising one plane can pass while the other has been broken for a month.
     */
    public function register(ExternalActions $actions): mixed
    {
        // OUTBOUND REACH, handed to an account whose address nobody has confirmed.
        // This connection makes the platform ITSELF send requests to a URL the creator
        // chose, or pull identities from one — the same class as a webhook, which this
        // console has always gated. Internal bookkeeping (an access review, a policy) is
        // deliberately not held this way; reach outside the tenant is.
        if (app(ConsoleScope::class)->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('register a hook');
        }

        // Both are public props, so a crafted wire request can set either to anything.
        // `hook` especially: without the enum rule the HookPoint::from() below throws
        // ValueError and the console 500s instead of refusing the input. Stated here in
        // one place rather than split between attributes and this call, so the rules
        // cannot drift apart.
        $this->validate([
            'hook' => ['required', Rule::enum(HookPoint::class)],
            'url' => ['required', 'url', 'max:500'],
        ]);

        try {
            $organizationId = $this->targetOrganizationId();
        } catch (AuthorizationException $e) {
            // Reported on the URL field rather than thrown: on the environment plane
            // "you have not picked an organization yet" is an ordinary state of the
            // console, not a failure, and the form must survive to be resubmitted.
            $this->addError('url', $e->getMessage());

            return null;
        }

        // LAST, after authorization and after the refusals above — the order every other
        // credential in this console uses. Normally a no-op, because mount() already asked.
        if ($this->stepUpPending()) {
            return null;
        }

        // Two calls rather than one with a nullable argument. The registry no longer lets
        // "for every tenant here" be expressed by a variable that happens to be null, so
        // the environment-wide case is stated at the one call site entitled to make it.
        $registered = $organizationId === null
            ? $actions->registerForEnvironment(HookPoint::from($this->hook), $this->url)
            : $actions->register(HookPoint::from($this->hook), $this->url, $organizationId);

        // The plaintext secret exists only in this response; hand it to the detail page
        // as a one-time flash — it is never retrievable again.
        session()->flash('newSecret', $registered->secret);
        $this->dispatch('toast', message: 'Inline hook endpoint registered.');

        return $this->redirectRoute(
            app(ConsoleScope::class)->routeName('hooks.show'),
            ['hook' => $registered->endpoint->id],
            navigate: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'hookPoints' => HookPoint::cases(),
            // The one branch a page is allowed to make on the plane: whether the
            // administrator acts on several organizations or implicitly on their own.
            'mayScopeEnvironmentWide' => app(ConsoleScope::class)->plane()->choosesOrganization(),
        ];
    }

    /**
     * True when the administrator has been sent to re-enter their password first.
     *
     * Registration mints the secret the endpoint verifies our calls with and hands it
     * over in plaintext. There is no rotation on this resource at all, so creation is not
     * merely one way to reach the credential — it is the only one, which makes an ungated
     * create the whole surface rather than a way around a gate.
     */
    private function stepUpPending(): bool
    {
        $sudo = app(ConsoleStepUp::class)->challenge(
            'hooks.create',
            'environment.hooks.create',
            [],
            'Registering an inline hook issues a signing secret and puts your endpoint inside the sign-in path.',
        );

        if ($sudo === null) {
            return false;
        }

        $this->redirectRoute($sudo, navigate: false);

        return true;
    }

    /**
     * The organization this endpoint is registered for, or null for environment-wide.
     *
     * @throws AuthorizationException when no organization is resolved
     */
    private function targetOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        if (! $this->environmentWide) {
            // The organization comes from the scope, not from a field on this form.
            return $scope->requireOrganizationId();
        }

        // An endpoint with no organization runs at this hook point for EVERY tenant in
        // the environment — and at most of these points it can refuse the operation, so
        // a tenant minting one could stop every other tenant signing in. A tenant
        // administrator may never mint one, and "the checkbox is not rendered for them"
        // is not the guard: the property is client-settable.
        abort_unless($scope->plane() === ConsolePlane::Environment, 403);

        return null;
    }
}; ?>

<div>
    <a href="{{ route($scopeRoute('hooks')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Inline hooks</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New inline hook</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">The signing secret is shown once, right after you register the endpoint.</p>

    <form wire:submit="register" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="hook">Hook point</label>
            <select wire:model="hook" id="hook" class="select">
                @foreach ($hookPoints as $hookPoint)
                    <option value="{{ $hookPoint->value }}">{{ $hookPoint->label() }} — {{ $hookPoint->description() }}</option>
                @endforeach
            </select>
            @error('hook') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        @if ($mayScopeEnvironmentWide)
            <div>
                <label class="flex items-start gap-2" for="environmentWide">
                    <input wire:model="environmentWide" id="environmentWide" type="checkbox" class="mt-1">
                    <span>
                        <span class="label">Environment-wide</span>
                        <span class="block text-xs" style="color:var(--faint)">Call this endpoint for every organization in this environment, not just the one selected in the bar above.</span>
                    </span>
                </label>
            </div>
        @endif

        <div>
            <label class="label" for="url">Endpoint URL</label>
            <input @error('url') aria-invalid="true" aria-describedby="url-error" @enderror wire:model="url" id="url" type="url" class="input mono" placeholder="https://example.com/hooks/token" autofocus>
            @error('url') <p id="url-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="register">Register endpoint</button>
            <a href="{{ route($scopeRoute('hooks')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
