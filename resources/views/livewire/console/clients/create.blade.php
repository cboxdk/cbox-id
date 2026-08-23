<?php

declare(strict_types=1);

use App\Platform\AppKind;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\ScopeCatalog;
use App\Platform\VerifiedEmailGate;
use App\Rules\SecureRedirectUri;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Apps & API keys › New. A dedicated, deep-linkable create page for
 * registering an OAuth client — an app that signs people in, a machine credential that
 * calls the API, or both.
 *
 * A confidential client is issued a secret exactly once. The plaintext is flashed into
 * the session and the page routes to the app's detail page, which reveals it a single
 * time beside the values needed to wire the app up — only the SHA-256 hash is stored, so
 * it is never retrievable again (rotate mints a fresh one, shown once).
 *
 * Which is why this page is behind the same step-up as rotation ({@see ConsoleStepUp}).
 * It hands over the identical credential; a gate on only the rotate button would have
 * been a gate on the more expensive of two ways to get one.
 *
 * One component, both planes. The organization plane registered from an inline form on
 * the list with the organization implied; the environment plane from this page, with the
 * organization implied the other way — it always registered an app the ENVIRONMENT owns.
 * That was the picker's hidden capability, invisible because there was no picker: an
 * organization-less client belongs to the platform rather than to a tenant, and a
 * first-party one appears in every organization's launcher. So it survives as its own
 * explicit choice, offered and honoured on the environment plane only.
 */
new #[Layout('components.layouts.console', ['title' => 'New app'])] class extends Component
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
     * Ask for the step-up at the door, before there is a form to lose.
     *
     * The gate that actually enforces is the one in {@see create()} — mount() never runs
     * for a crafted POST to the update endpoint. This one is here for the person: a
     * password prompt raised on submit sends them to another page and back to an empty
     * form, and the form is where they have just described an app. Nothing on this page
     * is worth reading before the prompt, so the prompt comes first.
     *
     * Deliberately NOT in boot(): boot() re-runs on every wire:model.live round trip, so
     * a window lapsing while the form sat open would throw the work away mid-typing.
     * mount() plus the write is the pair that costs nothing and protects everything.
     */
    public function mount(): void
    {
        $this->stepUpPending();
    }

    public string $name = '';

    /**
     * The one question. Everything the specification calls a decision — client type,
     * grants, which scopes to register for — follows from it. {@see AppKind}.
     */
    public string $kind = 'web';

    /** confidential = has a secret (server-side); public = PKCE, no secret (SPA/native/mobile). */
    public string $type = 'confidential';

    public bool $grantAuthorizationCode = true;

    public bool $grantClientCredentials = false;

    public string $redirectUris = '';

    /** Where sign-out may send people back to. Validated exactly like redirect URIs. */
    public string $postLogoutRedirectUris = '';

    /** @var array<int, string> Scopes ticked from the catalog. */
    public array $selectedScopes = ['openid', 'profile', 'email', 'offline_access'];

    /**
     * Which kind the ticked scopes came from, so {@see self::updatedKind()} can tell a
     * list somebody curated from one it wrote itself.
     */
    public string $kindWhenScopesWereSet = 'web';

    /** Advanced: any extra custom scope keys, comma-separated. */
    public string $customScopes = '';

    /** First-party clients skip the consent screen and surface in the app launcher. */
    public bool $firstParty = false;

    /** Where this app publishes its role/permission manifest for Cbox ID to pull. */
    public string $manifestUrl = '';

    /**
     * Register an app the ENVIRONMENT owns rather than one organization's.
     * Offered on the environment plane only — and refused there and then if it arrives
     * from anywhere else, because a Livewire property is client-settable and a control
     * that is merely unrendered is not a control that is enforced.
     */
    public bool $environmentWide = false;

    /**
     * Follow the chosen kind, unless the person has already disagreed with it.
     *
     * Re-applying the defaults on every change would silently undo a deliberate tick —
     * so it only writes the scopes while they still are the previous kind's defaults.
     * Somebody who has curated the list keeps their list.
     */
    public function updatedKind(string $value): void
    {
        $previous = AppKind::tryFrom($this->kindWhenScopesWereSet) ?? AppKind::WebApp;
        $next = AppKind::tryFrom($value) ?? AppKind::WebApp;

        // COMPARED ON A COPY. `sort()` takes its argument by reference, so sorting the
        // property to compare it also reordered the list the person sees and the one that
        // is registered — `openid`, which every example puts first, arrived fourth.
        $current = $this->selectedScopes;
        sort($current);
        $expected = $previous->defaultScopes();
        sort($expected);

        $untouched = $current === $expected;

        if ($untouched) {
            $this->selectedScopes = $next->defaultScopes();
        }

        $this->kindWhenScopesWereSet = $value;

        if ($next !== AppKind::Advanced) {
            $this->type = $next->clientType() === ClientType::Public ? 'public' : 'confidential';
        }
    }

    public function create(ClientRegistry $clients, ScopeCatalog $catalog): mixed
    {
        // Held on the organization plane only, because that is the plane the gate is
        // about: a social sign-in creates a subject immediately with an address only the
        // provider vouched for, and an app is a durable object other people will trust.
        // An environment administrator authenticates as an account member, where there is
        // no subject to read — the gate would answer "unverified" for every one of them
        // and lock the plane out of registering apps at all.
        if (app(ConsoleScope::class)->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('create an application');
        }

        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'kind' => ['required', 'in:'.implode(',', array_column(AppKind::cases(), 'value'))],
            'type' => ['required', 'in:confidential,public'],
            'grantAuthorizationCode' => ['boolean'],
            'grantClientCredentials' => ['boolean'],
            'customScopes' => ['nullable', 'string', 'max:500'],
            'redirectUris' => ['nullable', 'string', 'max:2000'],
            'postLogoutRedirectUris' => ['nullable', 'string', 'max:2000'],
            'manifestUrl' => ['nullable', 'url', 'max:500'],
        ]);

        $kind = AppKind::tryFrom($this->kind) ?? AppKind::WebApp;

        $grantTypes = $kind === AppKind::Advanced
            ? $this->parsedGrantTypes()
            : $kind->grantTypes();

        if ($grantTypes === []) {
            $this->addError('grantAuthorizationCode', 'Choose at least one way this app connects.');

            return null;
        }

        $redirects = $this->splitLines($this->redirectUris);
        $postLogoutRedirects = $this->splitLines($this->postLogoutRedirectUris);

        if (in_array('authorization_code', $grantTypes, true)) {
            if ($redirects === []) {
                $this->addError('redirectUris', 'A browser-login app needs at least one redirect URI to return people to.');

                return null;
            }
            foreach ($redirects as $uri) {
                if (! SecureRedirectUri::isSecure($uri)) {
                    $this->addError('redirectUris', 'Each redirect URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/callback.');

                    return null;
                }
            }
        }

        // Held to the same bar as the sign-in redirect URIs: sign-out hands the
        // browser to this address, so it must not be a cleartext public URL.
        foreach ($postLogoutRedirects as $uri) {
            if (! SecureRedirectUri::isSecure($uri)) {
                $this->addError('postLogoutRedirectUris', 'Each sign-out URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/signed-out.');

                return null;
            }
        }

        try {
            $organizationId = $this->targetOrganizationId();
        } catch (AuthorizationException $e) {
            // Reported on the name field rather than thrown: on the environment plane
            // "you have not picked an organization yet" is an ordinary state of the
            // console, not a failure, and the form must survive to be resubmitted.
            $this->addError('name', $e->getMessage());

            return null;
        }

        // LAST, after authorization and after every refusal above — the same order the
        // rotation on the detail page uses, and for the same two reasons: a step-up in
        // front of a 403 hands somebody who may not register anything a password prompt
        // instead of a refusal, and one in front of a validation error teaches people to
        // type it unread. Normally a no-op, because mount() already asked.
        if ($this->stepUpPending()) {
            return null;
        }

        // Only catalog scopes from the picker, plus any advanced custom keys.
        $scopes = array_values(array_unique(array_merge(
            array_values(array_intersect($this->selectedScopes, $catalog->keys())),
            $this->parsedCustomScopes(),
        )));

        // The kind decides where the code runs, and therefore whether it can hold a
        // secret. Only Advanced lets that be answered by hand — a person who has told us
        // they are building a CLI has already told us it is public.
        $clientType = $kind === AppKind::Advanced
            ? ($this->type === 'public' ? ClientType::Public : ClientType::Confidential)
            : $kind->clientType();

        $registered = $clients->register(new NewClient(
            name: trim($this->name),
            type: $clientType,
            redirectUris: $redirects,
            grantTypes: $grantTypes,
            scopes: $scopes,
            firstParty: $this->firstParty,
            organizationId: $organizationId,
            postLogoutRedirectUris: $postLogoutRedirects,
        ));

        // A published manifest URL (the pull transport) — stored on the app so the
        // scheduled sweep + "Sync now" can fetch its declared roles/permissions.
        if (trim($this->manifestUrl) !== '') {
            $registered->client->forceFill(['manifest_url' => trim($this->manifestUrl)])->save();
        }

        // The plaintext secret exists only here. Hand it to the detail page once via a
        // flash — it is aged out after that render, so it is never shown a second time.
        if ($registered->secret !== null && $registered->secret !== '') {
            session()->flash('revealed_secret', $registered->secret);
        }

        $this->dispatch('toast', message: 'App "'.$registered->client->name.'" created.');

        return $this->redirectRoute(
            app(ConsoleScope::class)->routeName('clients.show'),
            ['client' => $registered->client->id],
            navigate: true,
        );
    }

    /**
     * True when the administrator has been sent to re-enter their password first.
     *
     * Registering a confidential app mints `csec_…` and puts it on the next screen, which
     * is the same live credential rotation hands over — so it answers to the same gate.
     * Gating rotation alone only moved the cheap path: create a second app instead of
     * re-keying the first, and the plaintext arrives just the same.
     */
    private function stepUpPending(): bool
    {
        $sudo = app(ConsoleStepUp::class)->challenge(
            'clients.create',
            'environment.clients.create',
            [],
            'Registering an app issues a client secret, shown once on the next screen — it signs in as this app until it is rotated.',
        );

        if ($sudo === null) {
            return false;
        }

        $this->redirectRoute($sudo, navigate: false);

        return true;
    }

    /**
     * The organization this app is registered for, or null for an environment-owned one.
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

        // An app with no organization is the platform's own: marked first-party it skips
        // the consent screen for EVERY organization in the environment and appears in
        // each of their launchers. A tenant administrator may never mint one, and "the
        // checkbox is not rendered for them" is not the guard — the property is
        // client-settable.
        abort_unless($scope->plane() === ConsolePlane::Environment, 403);

        return null;
    }

    /** @return list<string> */
    private function parsedGrantTypes(): array
    {
        $grants = [];
        if ($this->grantAuthorizationCode) {
            $grants[] = 'authorization_code';
            $grants[] = 'refresh_token';
        }
        if ($this->grantClientCredentials) {
            $grants[] = 'client_credentials';
        }

        return $grants;
    }

    /** @return list<string> */
    private function parsedCustomScopes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->customScopes),
        ), fn (string $scope): bool => $scope !== ''));
    }

    /** @return list<string> */
    private function splitLines(string $value): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n]+/', $value) ?: [],
        ), fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ScopeCatalog $catalog): array
    {
        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'scopeGroups' => $catalog->grouped(),
            'appKinds' => AppKind::offered(),
            'selectedKind' => $kind = AppKind::tryFrom($this->kind) ?? AppKind::WebApp,
            // Asked of the KIND, not of a checkbox: a CLI has no callback URL, and the
            // field asking for one was what made people believe they had chosen wrong.
            'needsRedirectUris' => $kind === AppKind::Advanced
                ? $this->grantAuthorizationCode
                : $kind->needsRedirectUris(),
            // The one branch a page is allowed to make on the plane: whether the
            // administrator acts on several organizations or implicitly on their own.
            'mayScopeEnvironmentWide' => app(ConsoleScope::class)->plane()->choosesOrganization(),
        ];
    }
}; ?>

<div>
    <a href="{{ route($scopeRoute('clients')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Apps & API keys</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New app</h1>
    {{-- The old subtitle named exactly two modes — "signing people in" and
         "machine-to-machine" — which is the same two-checkbox model the form has stopped
         using, and it excluded a CLI and an agent by omission. It also promised a client
         secret, which a public app never receives; the sentence under the kind picker
         says which of the two this one is, once the answer is known. --}}
    <p class="mt-1 text-sm" style="color:var(--muted)">Register an app so it can sign your people in, act on their behalf, or call the API as itself. Answer what kind it is and Cbox ID picks the flow, the credentials and the scopes to match.</p>

    <form wire:submit="create" class="mt-6 max-w-2xl rounded-xl border p-5 space-y-5" style="border-color:var(--border)">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">App name</label>
                <input @error('name') aria-invalid="true" aria-describedby="name-error" @enderror wire:model="name" id="name" type="text" class="input" placeholder="Support Portal" autofocus>
                @error('name') <p id="name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- THE ONE QUESTION. Client type, grants and the scope ceiling all follow from
             it, so they are answered once here instead of four times in the vocabulary of
             the specification. {@see \App\Platform\AppKind} --}}
        <fieldset>
            <legend class="label">What kind of app is this?</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                @foreach ($appKinds as $option)
                    <label class="flex items-start gap-2.5 rounded-lg p-3 cursor-pointer"
                           style="border:1px solid {{ $kind === $option->value ? 'var(--primary)' : 'var(--border)' }}"
                           wire:key="kind-{{ $option->value }}">
                        <input wire:model.live="kind" type="radio" name="kind" value="{{ $option->value }}" class="mt-0.5">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ $option->label() }}</span>
                            <span class="block text-xs mt-0.5" style="color:var(--muted)">{{ $option->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('kind') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </fieldset>

        @if ($selectedKind === \App\Platform\AppKind::Advanced)
            <div>
                <label class="label" for="type">Client type</label>
                <select wire:model="type" id="type" class="select">
                    <option value="confidential">Confidential — a server that can keep a secret</option>
                    <option value="public">Public — a browser/mobile app (PKCE, no secret)</option>
                </select>
                @error('type') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @else
            <p class="text-xs" style="color:var(--muted)">
                {{-- Said out loud rather than inferred silently: somebody who later reads
                     "public client" on the detail page should have met the words here. --}}
                Registered as a
                <b>{{ $selectedKind->clientType() === \Cbox\Id\OAuthServer\Enums\ClientType::Public ? 'public' : 'confidential' }}</b>
                client
                @if ($selectedKind->clientType() === \Cbox\Id\OAuthServer\Enums\ClientType::Public)
                    — it holds no secret, because the code runs where a secret could be read.
                @else
                    — it is issued a secret, shown once on the next screen.
                @endif
            </p>
        @endif

        @if ($mayScopeEnvironmentWide)
            <div>
                <label class="flex items-start gap-2" for="environmentWide">
                    <input wire:model="environmentWide" id="environmentWide" type="checkbox" class="mt-1">
                    <span>
                        <span class="label">Environment-wide</span>
                        <span class="block text-xs" style="color:var(--faint)">The platform owns this app rather than the organization selected in the bar above. A first-party environment-wide app skips the consent screen and appears in every organization's launcher.</span>
                    </span>
                </label>
            </div>
        @endif

        {{-- The handshake this kind actually performs, so the choice above can be
             checked against what the person expects to happen. --}}
        @if ($selectedKind === \App\Platform\AppKind::Advanced)
            <div>
                <span class="label">How does this app connect?</span>
                <div class="mt-1 flex flex-wrap gap-x-6 gap-y-2 mb-3">
                    <label class="flex items-center gap-2 text-sm">
                        <input wire:model.live="grantAuthorizationCode" type="checkbox" class="rounded"> Sign people in <span style="color:var(--muted)">(single sign-on)</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input wire:model.live="grantClientCredentials" type="checkbox" class="rounded"> Call the API as itself <span style="color:var(--muted)">(machine-to-machine)</span>
                    </label>
                </div>
                @error('grantAuthorizationCode') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @elseif ($selectedKind->flow() !== [])
            <div class="cbx-flow" role="img" aria-label="How {{ $selectedKind->label() }} signs in: {{ strip_tags(implode(', then ', $selectedKind->flow())) }}">
                @foreach ($selectedKind->flow() as $i => $step)
                    @if ($i > 0)<span class="cbx-flow-arrow">→</span>@endif
                    <div class="cbx-flow-step">
                        <span class="cbx-flow-dot" style="background:{{ $loop->last ? 'var(--success-soft)' : 'var(--accent-soft)' }};color:{{ $loop->last ? 'var(--success-strong)' : 'var(--primary)' }}">{{ $i + 1 }}</span>
                        <span>{!! $step !!}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($needsRedirectUris)
            <div>
                <label class="label" for="redirectUris">Redirect URIs <span style="color:var(--faint);font-weight:400">— where Cbox ID sends people back (one per line)</span></label>
                <textarea @error('redirectUris') aria-invalid="true" aria-describedby="redirectUris-error" @enderror wire:model="redirectUris" id="redirectUris" rows="2" class="input mono" style="height:auto;padding:8px 10px;font-size:0.78rem" placeholder="https://app.example.com/auth/callback"></textarea>
                @error('redirectUris') <p id="redirectUris-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="postLogoutRedirectUris">Sign-out URIs <span style="color:var(--faint);font-weight:400">— where Cbox ID sends people after signing out (one per line)</span></label>
                <textarea @error('postLogoutRedirectUris') aria-invalid="true" aria-describedby="postLogoutRedirectUris-error" @enderror wire:model="postLogoutRedirectUris" id="postLogoutRedirectUris" rows="2" class="input mono" style="height:auto;padding:8px 10px;font-size:0.78rem" placeholder="https://app.example.com/signed-out"></textarea>
                <p class="mt-1 text-xs" style="color:var(--muted)">When the app signs someone out, it asks Cbox ID to send them on to one of these addresses. The URI the app asks for has to appear here character for character — trailing slash and all — or Cbox ID leaves the person on its own signed-out page. Leave empty if the app never sends people back.</p>
                @error('postLogoutRedirectUris') <p id="postLogoutRedirectUris-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif

        {{-- SCOPES, AND CALLED SCOPES. This box said "Permissions this app requests",
             and the console has a Permissions page a few links away that means something
             else entirely: a scope is the ceiling on what THIS APP may ask for, a
             permission is what a PERSON is allowed to do. One word for two concepts is
             most of why the difference is hard to explain — so the word goes to the page
             that owns it, and this one says what it is. --}}
        <div>
            <span class="label">What this app may ask for</span>
            <p class="mt-1 text-xs" style="color:var(--muted)">
                Scopes — the ceiling on this app's access. It can request these and nothing
                else; people see the sign-in ones on the consent screen (first-party apps
                skip it). Separate from
                <a href="{{ route($scopeRoute('permissions')) }}" wire:navigate class="underline">Permissions</a>,
                which is what a <em>person</em> is allowed to do once signed in.
            </p>
            <div class="mt-3 space-y-4">
                @foreach ($scopeGroups as $group => $scopes)
                    <div wire:key="scopegroup-{{ $group }}">
                        <p class="text-xs font-semibold uppercase mb-2" style="color:var(--muted);letter-spacing:0.05em">{{ $group }}</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($scopes as $scope)
                                <label class="flex items-start gap-2.5 rounded-lg p-2.5 cursor-pointer" style="border:1px solid var(--border)">
                                    <input wire:model="selectedScopes" type="checkbox" value="{{ $scope['key'] }}" class="mt-0.5 rounded">
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2 flex-wrap">
                                            <span class="text-sm font-medium">{{ $scope['label'] }}</span>
                                            <span class="text-xs rounded-full px-2 py-0.5 mono" style="background:var(--surface-2);color:var(--muted)">{{ $scope['key'] }}</span>
                                        </span>
                                        <span class="block text-xs mt-0.5" style="color:var(--muted)">{{ $scope['description'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <div>
                    <label class="label" for="customScopes" style="font-weight:400;font-size:0.75rem">Advanced — custom scopes <span style="color:var(--faint)">(comma-separated)</span></label>
                    <input @error('customScopes') aria-invalid="true" aria-describedby="customScopes-error" @enderror wire:model="customScopes" id="customScopes" type="text" class="input mono" placeholder="reports.read">
                    @error('customScopes') <p id="customScopes-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <label class="flex items-start gap-2.5 text-sm">
            <input wire:model="firstParty" type="checkbox" class="mt-0.5 rounded">
            <span>
                First-party app
                <span class="block text-xs" style="color:var(--muted)">A trusted app you own — skips the consent screen and appears in the app launcher (needs a redirect URI).</span>
            </span>
        </label>

        <div>
            <label class="label" for="manifestUrl">Manifest URL <span style="color:var(--faint);font-weight:400">— optional; where the app publishes its roles &amp; permissions</span></label>
            <input wire:model="manifestUrl" id="manifestUrl" type="url" class="input mono" placeholder="https://app.example.com/.well-known/cbox-authz">
            <p class="mt-1 text-xs" style="color:var(--muted)">Cbox ID pulls this to learn the app's roles. You can also set it later, or the app can push.</p>
            @error('manifestUrl') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-2 pt-1">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create app</button>
            <a href="{{ route($scopeRoute('clients')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
