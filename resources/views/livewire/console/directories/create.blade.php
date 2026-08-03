<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\DirectoryConnectors;
use Cbox\Id\Directory\DirectoryPullSync;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderParameter;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Sync users in › New. A dedicated, deep-linkable page for connecting a
 * directory, in either of the two shapes the platform supports:
 *
 *  - SCIM (push): we mint a bearer token the customer's identity provider authenticates
 *    every SCIM request with. It is returned in plaintext exactly once and stored only
 *    as a SHA-256 hash, so it is flashed to the detail page — surviving the single
 *    redirect and then gone — where it is revealed once.
 *  - API pull (Google Workspace, Microsoft Entra): we hold provider credentials, sealed
 *    at rest, and fetch the user set on a schedule. The credentials are verified against
 *    the provider before anything is stored, so a wrong key fails here rather than
 *    silently at 3am.
 *
 * One component, both planes — and this page is where the console's worst drift was. The
 * organization plane offered both pull providers; the environment plane offered SCIM and
 * nothing else, so an administrator who holds every organization in the environment
 * could not connect either of the two providers the product ships connectors for. That
 * asymmetry is what started the console merge, so it is the one thing this page must not
 * lose: the providers come from the enum, which is the public contract, filtered to
 * those with a registered connector (deny-by-default — a provider we could not actually
 * reach is not offered).
 *
 * `register` and `create` were the same capability under two names, one console each —
 * which is how a test exercising one plane can pass while the other has been broken for
 * a month. One action now, named for the domain verb {@see Directories::register} uses.
 *
 * The organization picker is gone: the console chrome owns which organization is being
 * administered, and a second copy of that answer on the form is how the two planes came
 * to validate it differently. Unlike the other merged capabilities there is no hidden
 * "All organizations" capability behind it — `directories.organization_id` is NOT NULL,
 * so the picker's only other option was a placeholder and an environment-wide directory
 * has never been representable.
 */
new #[Layout('components.layouts.console', ['title' => 'New directory'])] class extends Component
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

    /** Which shape of directory is being connected. Validated against the enum. */
    public string $provider = 'scim';

    public string $name = '';

    /*
     * The pull-provider credentials.
     *
     * Public because they are bound form fields, which means they DO ride the wire
     * snapshot for as long as the form is open — unavoidable for something the
     * administrator is typing, and the reason they are reset the moment the connection
     * succeeds. From then on the only copy is the sealed one on the directory row;
     * nothing on this page or the detail page ever reads it back out.
     */
    public string $googleServiceAccountJson = '';

    public string $googleAdminEmail = '';

    public string $entraTenantId = '';

    public string $entraClientId = '';

    public string $entraClientSecret = '';

    /** Why the provider refused, in the administrator's words rather than the API's. */
    public ?string $connectError = null;

    /**
     * Register a SCIM (push) directory, mint its bearer token, and route to the page
     * that reveals it.
     */
    public function register(Directories $directories): mixed
    {
        // Asked before any other work: a non-entitled organization is refused outright
        // rather than walked through a form it may not submit.
        $organizationId = $this->targetOrganizationId();

        $this->validate(['name' => ['required', 'string', 'max:120']]);

        if ($organizationId === null) {
            // Reported on the field rather than thrown: on the environment plane "I have
            // not picked an organization yet" is an ordinary state of the console, not a
            // failure, and the form must survive to be resubmitted.
            $this->addError('name', 'Choose an organization before connecting a directory.');

            return null;
        }

        $registered = $directories->register($organizationId, trim($this->name));

        // Reveal the plaintext bearer token exactly once, on the detail page. Only its
        // hash is persisted, so it can never be retrieved again after this hand-off.
        session()->flash('newToken', $registered->token);
        $this->dispatch('toast', message: 'Directory registered — copy the bearer token, it is shown only once.');

        return $this->redirectRoute(
            app(ConsoleScope::class)->routeName('directories.show'),
            ['directory' => $registered->directory->id],
            navigate: true,
        );
    }

    /**
     * Connect an API-pull directory: verify the credentials against the provider,
     * register it with them sealed, and run the first sync now.
     *
     * The verify step is the point of the page. Storing credentials we have never used
     * means the first anyone hears of a wrong key is a nightly sync that quietly
     * provisions nobody.
     */
    public function connectPull(Directories $directories, DirectoryConnectors $connectors, DirectoryPullSync $sync): mixed
    {
        $organizationId = $this->targetOrganizationId();
        $this->connectError = null;

        $provider = DirectoryProvider::tryFrom($this->provider);

        // `provider` is a public property, so a crafted wire request can set it to
        // anything — including `scim`, which has no pull connector at all. Refused here
        // rather than left to raise inside the registry.
        if ($provider === null || ! $provider->isPull() || ! $connectors->has($provider)) {
            $this->connectError = 'Choose a directory provider.';

            return null;
        }

        if ($organizationId === null) {
            $this->connectError = 'Choose an organization before connecting a directory.';

            return null;
        }

        $credentials = $this->pullCredentials($provider);

        if ($credentials === null) {
            return null;
        }

        if (! $connectors->for($provider)->verify($credentials)) {
            $this->connectError = 'Could not connect to '.$provider->label().' — check the credentials and admin consent.';

            return null;
        }

        $directory = $directories->registerPull($organizationId, $provider->label(), $provider, $credentials);

        // Kick off the first sync now, so the administrator watches people arrive rather
        // than an empty directory. A failure is recorded on the directory itself
        // (last_sync_error) and surfaced on the list and detail pages.
        try {
            $sync->sync($directory);
        } catch (Throwable) {
            // Already stored on last_sync_error by the sync. The connection itself
            // succeeded and is not rolled back because one fetch did not.
        }

        // The credentials are sealed on the row now; drop the copies this form was
        // holding so they stop riding the wire snapshot.
        $this->reset('googleServiceAccountJson', 'googleAdminEmail', 'entraTenantId', 'entraClientId', 'entraClientSecret');
        $this->dispatch('toast', message: $provider->label().' connected — users are syncing.');

        return $this->redirectRoute(
            app(ConsoleScope::class)->routeName('directories.show'),
            ['directory' => $directory->id],
            navigate: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);
        $organizationId = $this->actingOrganizationId();
        $connectors = app(DirectoryConnectors::class);
        $selected = DirectoryProvider::tryFrom($this->provider);

        // The catalogue entry behind the provider being connected, if there is one.
        //
        // This page used to have nothing to say. The steps for connecting Google as a
        // directory existed — in the framework, beside the steps for connecting Google
        // for sign-in — and the two registries naming the same provider shared nothing,
        // so the screen could not reach them. An administrator who had just done the
        // sign-in half got an empty credential box and no hint that a directory wants a
        // service account rather than the OAuth client they had in front of them.
        //
        // Null for SCIM, which has no catalogue entry by design (it is a protocol the
        // customer's provider speaks to US — the fields on that form are ours, not
        // theirs). Null is an enrichment that did not arrive, never a reason to withhold
        // the form: a provider we can reach must stay connectable even if nobody has
        // written its guide yet.
        $template = $selected === null ? null : ProviderCatalog::forDirectory($selected);

        return [
            'template' => $template,
            // The credential fields the connector actually reads, so the labels and the
            // help on this form come from the same declaration the connector is checked
            // against rather than from a second copy that ages here.
            'credential' => fn (string $key): ?ProviderParameter => $this->credential($template, $key),
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // The enum is the public contract, so both planes offer what it publishes —
            // minus any provider with no registered connector, which could not actually
            // be reached if it were chosen. Still the enum and not the catalogue: this is
            // the list of things that can be STORED on a directory row, and SCIM is one
            // of them without being a catalogue provider at all.
            'providers' => array_values(array_filter(
                DirectoryProvider::cases(),
                fn (DirectoryProvider $option): bool => ! $option->isPull() || $connectors->has($option),
            )),
            // Told apart deliberately: "you have not chosen an organization" and "this
            // organization is not entitled" are different problems with different fixes,
            // and `entitled()` answers false for both.
            'organizationChosen' => $organizationId !== null,
            'entitled' => $organizationId !== null && $scope->entitled('scim'),
        ];
    }

    /**
     * The organization being administered, or null when the environment plane has not
     * chosen one yet.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both. On the
     * organization plane there is nothing to choose, so a member with no organization at
     * all is refused rather than shown a form asking them to pick one.
     */
    private function actingOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();
    }

    /**
     * The organization to connect this directory for, with the entitlement enforced.
     *
     * The entitlement is a hard 403 — but only once an organization IS resolved.
     * `entitled()` answers false for "none chosen" too, and 403-ing on that would tell
     * an environment administrator to contact their account team about a decision they
     * have simply not made yet.
     */
    private function targetOrganizationId(): ?string
    {
        $organizationId = $this->actingOrganizationId();

        if ($organizationId === null) {
            return null;
        }

        abort_unless(app(ConsoleScope::class)->entitled('scim'), 403);

        return $organizationId;
    }

    /**
     * One declared credential field of a catalogue entry's directory setup.
     *
     * The form keeps its own typed properties — these are values an administrator types
     * and they must stay bound to named fields — but what they are CALLED and where to
     * find them comes from the catalogue, which is the same declaration the framework
     * checks the connector against. Two copies of "what does Entra call this" is how one
     * of them ends up describing a permission the connector stopped needing.
     */
    private function credential(?ProviderTemplate $template, string $key): ?ProviderParameter
    {
        $setup = $template?->directory;

        if ($setup === null) {
            return null;
        }

        foreach ($setup->credentials as $credential) {
            if ($credential->key === $key) {
                return $credential;
            }
        }

        return null;
    }

    /**
     * The provider-specific credential map, or null with the reason on screen.
     *
     * Shaped here rather than in the connector because these are the fields an
     * administrator typed; the connector receives the map its API needs and nothing
     * about this form.
     *
     * @return array<string, mixed>|null
     */
    private function pullCredentials(DirectoryProvider $provider): ?array
    {
        if ($provider === DirectoryProvider::GoogleWorkspace) {
            $serviceAccount = json_decode($this->googleServiceAccountJson, true);

            if (! is_array($serviceAccount) || ! is_string($serviceAccount['client_email'] ?? null) || ! is_string($serviceAccount['private_key'] ?? null)) {
                $this->connectError = 'Paste the full service-account JSON key (it must contain client_email and private_key).';

                return null;
            }

            if (trim($this->googleAdminEmail) === '') {
                $this->connectError = 'Enter the admin email to impersonate.';

                return null;
            }

            return [
                'client_email' => $serviceAccount['client_email'],
                'private_key' => $serviceAccount['private_key'],
                'admin_email' => trim($this->googleAdminEmail),
            ];
        }

        if (trim($this->entraTenantId) === '' || trim($this->entraClientId) === '' || trim($this->entraClientSecret) === '') {
            $this->connectError = 'Enter the Entra tenant ID, client ID, and client secret.';

            return null;
        }

        return [
            'tenant_id' => trim($this->entraTenantId),
            'client_id' => trim($this->entraClientId),
            'client_secret' => trim($this->entraClientSecret),
        ];
    }
}; ?>

<div>
    <a href="{{ route($scopeRoute('directories')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Sync users in</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New directory</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Either point an identity provider at our SCIM endpoint, or connect Google Workspace or Microsoft Entra directly and we will pull your people on a schedule.</p>

    @if ($organizationChosen && ! $entitled)
        <div class="mt-6 max-w-xl rounded-xl border p-5" style="border-color:var(--border)">
            <p class="text-sm" style="color:var(--muted)">Syncing users in is an Enterprise feature. Contact your account team to enable it for this organization.</p>
            <a href="{{ route($scopeRoute('directories')) }}" class="btn btn-ghost mt-4">Back to Sync users in</a>
        </div>
    @elseif (! $organizationChosen)
        {{-- Not the upsell: this administrator holds every organization here and has
             simply not said which one they are acting on. --}}
        <div class="mt-6 max-w-xl rounded-xl border p-5" style="border-color:var(--border)">
            <p class="text-sm" style="color:var(--muted)">Choose an organization in the bar above — a directory provisions one tenant's users, so there is nothing to connect it to yet.</p>
            <a href="{{ route($scopeRoute('directories')) }}" class="btn btn-ghost mt-4">Back to Sync users in</a>
        </div>
    @else
        <div class="mt-6 max-w-xl">
            <label class="label" for="provider">Provider</label>
            <select wire:model.live="provider" id="provider" class="select">
                @foreach ($providers as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs" style="color:var(--faint)">SCIM is a push: your provider sends changes to us. The others are pulls: we fetch from the provider's API on a schedule.</p>
        </div>

        @if ($provider === \Cbox\Id\Directory\Enums\DirectoryProvider::Scim->value)
            <form wire:submit="register" class="mt-4 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
                <div>
                    <label class="label" for="name">Directory name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Okta SCIM" autofocus
                           @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    <p class="mt-1 text-xs" style="color:var(--faint)">Registration issues a bearer token — shown once — that your identity provider authenticates with.</p>
                    @error('name') <p id="name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="register">Register directory</button>
                    <a href="{{ route($scopeRoute('directories')) }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        @else
            {{-- The provider's own guide, from the catalogue — the same place the sign-in
                 page gets Google's. It is a DIFFERENT guide from that one, and that is the
                 whole point: connecting Google for sign-in is an OAuth client and two
                 pasted strings, connecting it as a directory is a service account and a
                 domain-wide delegation grant made in a console the administrator may
                 never have opened. Before the catalogue carried both, this screen could
                 say neither. --}}
            @if ($template?->directory)
                <div class="mt-4 max-w-xl rounded-xl border" style="border-color:var(--border)">
                    <div class="p-4 border-b flex items-center gap-2.5" style="border-color:var(--border)">
                        <x-provider-mark :provider="$template->key" :size="22" />
                        <div class="min-w-0">
                            <h2 class="font-semibold text-sm" style="color:var(--foreground)">Set up {{ $template->directory->provider->label() }}</h2>
                            <p class="text-sm mt-0.5" style="color:var(--muted)">
                                <a href="{{ $template->directory->documentationUrl }}" target="_blank" rel="noopener noreferrer"
                                   class="underline underline-offset-2" style="color:var(--accent-strong)">{{ $template->name }}&rsquo;s own guide ↗</a>
                            </p>
                        </div>
                    </div>
                    <ol class="p-4 space-y-1.5 text-sm list-decimal ps-9" style="color:var(--muted)">
                        @foreach ($template->directory->setupSteps as $step)
                            <li wire:key="dir-step-{{ $template->key }}-{{ $loop->index }}">{{ $step }}</li>
                        @endforeach
                    </ol>
                </div>
            @endif

            {{-- The pull providers. Google Workspace has no SCIM support at all, so this
                 is the only path to it; Entra supports both and many customers prefer
                 pull. Neither was reachable from the environment plane before the merge. --}}
            <form wire:submit="connectPull" class="mt-4 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
                @if ($provider === \Cbox\Id\Directory\Enums\DirectoryProvider::GoogleWorkspace->value)
                    {{-- One textarea for two declared credentials: `client_email` and
                         `private_key` arrive together in the file Google hands over, and
                         asking somebody to pick two fields out of a JSON blob is how one
                         of them arrives with a newline eaten. Decomposed in
                         pullCredentials() into exactly the keys the connector reads. --}}
                    <div>
                        <label class="label" for="googleServiceAccountJson">Service-account JSON key</label>
                        <textarea wire:model="googleServiceAccountJson" id="googleServiceAccountJson" rows="4" class="input mono text-xs" placeholder='{ "client_email": "...@...iam.gserviceaccount.com", "private_key": "-----BEGIN PRIVATE KEY-----\n..." }'></textarea>
                        <p class="mt-1 text-xs" style="color:var(--faint)">The whole file you downloaded — the service account email and its private key are read out of it.</p>
                    </div>
                    <div>
                        <label class="label" for="googleAdminEmail">{{ $credential('admin_email')?->label ?? 'Admin email to impersonate' }}</label>
                        <input wire:model="googleAdminEmail" id="googleAdminEmail" type="email" class="input" placeholder="{{ $credential('admin_email')?->example ?? 'admin@acme.com' }}">
                        @if ($help = $credential('admin_email')?->help)
                            <p class="mt-1 text-xs" style="color:var(--faint)">{{ $help }}</p>
                        @endif
                    </div>
                @else
                    <div class="grid sm:grid-cols-3 gap-3">
                        <div>
                            <label class="label" for="entraTenantId">{{ $credential('tenant_id')?->label ?? 'Tenant ID' }}</label>
                            <input wire:model="entraTenantId" id="entraTenantId" type="text" class="input" placeholder="{{ $credential('tenant_id')?->example }}">
                        </div>
                        <div>
                            <label class="label" for="entraClientId">{{ $credential('client_id')?->label ?? 'Client ID' }}</label>
                            <input wire:model="entraClientId" id="entraClientId" type="text" class="input" placeholder="{{ $credential('client_id')?->example }}">
                        </div>
                        <div>
                            {{-- Typed, never rendered back: the secret rides the wire only
                                 while the form is open and is reset the moment it seals. --}}
                            <label class="label" for="entraClientSecret">{{ $credential('client_secret')?->label ?? 'Client secret' }}</label>
                            <input wire:model="entraClientSecret" id="entraClientSecret" type="password" class="input">
                        </div>
                    </div>
                    @if ($help = $credential('client_secret')?->help)
                        <p class="text-xs" style="color:var(--faint)">{{ $help }}</p>
                    @endif
                @endif

                @if ($connectError)<p class="field-error" role="alert">{{ $connectError }}</p>@endif

                <div class="flex items-center gap-2">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="connectPull">
                        <span wire:loading.remove wire:target="connectPull">Connect &amp; sync</span>
                        <span wire:loading wire:target="connectPull">Connecting…</span>
                    </button>
                    <a href="{{ route($scopeRoute('directories')) }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        @endif
    @endif
</div>
