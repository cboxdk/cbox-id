<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ClientSecretKind;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Enums\ProviderCapability;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Pick a provider from a list instead of describing one from memory.
 *
 * Everything an administrator used to have to know — Google's issuer, that Entra's names
 * the directory, that GitHub is not an OpenID Provider at all, which scopes carry an
 * address — is catalogue data. What is left is the part that genuinely is theirs: the
 * client id and secret from their own account with that provider.
 *
 * The screen is built around the two things that actually go wrong. The redirect URI is
 * shown before anything else and is copyable, because "the redirect URI does not match"
 * is the single most common failure setting any of these up. And the provider's own
 * steps are shown beside the fields rather than linked away to, because the person
 * filling this in is switching between two browser tabs and every extra one costs them
 * their place.
 */
new #[Layout('components.layouts.console', ['title' => 'Social sign-in'])] class extends Component
{
    /** The catalogue key being set up, or null when browsing. */
    #[Locked]
    public ?string $selected = null;

    public string $clientId = '';

    public string $clientSecret = '';

    /** @var array<string, string> catalogue parameter key => value (Okta domain, Apple team id, …) */
    public array $parameters = [];

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    /**
     * Begin setting up a provider.
     *
     * `$key` is validated against the catalogue rather than trusted: it arrives from the
     * browser, and everything downstream — endpoints, scopes, the profile shape — is
     * looked up by it.
     */
    public function choose(string $key): void
    {
        $this->authorizeAdmin();

        if ($this->loginTemplate($key) === null) {
            return;
        }

        $this->selected = $key;
        $this->clientId = '';
        $this->clientSecret = '';
        $this->parameters = [];
        $this->resetErrorBag();
    }

    /**
     * A catalogue entry that can actually be used to sign somebody in, or null.
     *
     * The key arrives from the browser and everything downstream is looked up by it, so
     * it was already validated against the catalogue. Being IN the catalogue is no longer
     * the same question as being usable here: an entry can carry a directory and no
     * sign-in half, and this page would build a connection out of endpoints it does not
     * have. Deny-by-default, in the one place the key crosses in.
     */
    private function loginTemplate(string $key): ?ProviderTemplate
    {
        $template = ProviderCatalog::find($key);

        return $template?->supports(ProviderCapability::Login) === true ? $template : null;
    }

    public function cancel(): void
    {
        $this->selected = null;
        $this->clientId = '';
        $this->clientSecret = '';
        $this->parameters = [];
        $this->resetErrorBag();
    }

    public function enable(Connections $connections, OidcDiscovery $discovery): void
    {
        $this->authorizeAdmin();
        app(VerifiedEmailGate::class)->require('add a sign-in provider');

        $template = $this->loginTemplate((string) $this->selected);

        if ($template === null) {
            return;
        }

        // The secret is required only from a provider that issues one. Apple does not,
        // and demanding it made the one provider whose setup form we bothered to shape
        // specially the one provider nobody could finish enabling.
        $rules = ['clientId' => ['required', 'string', 'max:400']];

        if (! $this->mintsItsOwnSecret($template)) {
            $rules['clientSecret'] = ['required', 'string', 'max:5000'];
        }

        $this->validate($rules, attributes: [
            'clientId' => $this->mintsItsOwnSecret($template) ? 'Services ID' : 'client ID',
            'clientSecret' => 'client secret',
        ]);

        // Every missing parameter is reported at once. Reporting the first and stopping
        // would walk someone through Apple's three fields one round-trip at a time.
        $missing = 0;

        foreach ($template->parameters as $parameter) {
            if (trim($this->parameters[$parameter->key] ?? '') === '') {
                $this->addError('parameters.'.$parameter->key, $parameter->label.' is required.');
                $missing++;
            }
        }

        if ($missing > 0) {
            return;
        }

        $orgId = $this->requireOrgId();

        // One connection per provider per tenant. Two would each render a button with the
        // same name, and nothing on the sign-in page could tell a person which to press.
        foreach ($connections->catalogueProvidersFor($orgId) as $existing) {
            if ($existing->provider === $template->key) {
                $this->addError('clientId', $template->name.' is already enabled. Remove it first if you want to use different credentials.');

                return;
            }
        }

        $values = array_map(static fn (string $v): string => trim($v), $this->parameters);

        $config = [
            'provider' => $template->key,
            'client_id' => trim($this->clientId),
            ...($this->mintsItsOwnSecret($template) ? [] : ['client_secret' => trim($this->clientSecret)]),
            ...$values,
        ];

        if ($template->isOidc()) {
            $issuer = $template->issuerFor($values);

            if ($issuer === null) {
                $this->addError('clientId', 'Fill in every field above before enabling '.$template->name.'.');

                return;
            }

            $config['issuer'] = $issuer;

            try {
                // Discovery now, not at someone's first sign-in. An OIDC entry is cheap to
                // get wrong safely precisely because this runs here: a mistyped Okta domain
                // fails with the provider's own error while the administrator is still
                // looking at the form, rather than silently, later, for a user.
                $document = $discovery->fromIssuer($issuer);
                $config['authorization_endpoint'] = $document->authorizationEndpoint;
                $config['token_endpoint'] = $document->tokenEndpoint;
                $config['jwks_uri'] = $document->jwksUri;
            } catch (Throwable $e) {
                $this->addError('clientId', 'We could not reach '.$template->name.' at '.$issuer.' — check the details above. ('.$e->getMessage().')');

                return;
            }
        }

        try {
            $connection = $connections->create(
                organizationId: $orgId,
                type: $template->isOidc() ? ConnectionType::Oidc : ConnectionType::OAuth2,
                name: $template->name,
                config: $config,
                provider: $template->key,
            );
        } catch (InvalidAssertion $e) {
            $this->addError('clientId', $e->getMessage());

            return;
        }

        // Created as a draft, then activated: a half-saved provider must never appear as
        // a button on the sign-in page while someone is still typing.
        $connections->activate($orgId, $connection->id);

        $this->cancel();
        session()->flash('status', $template->name.' is now offered on your sign-in page.');
    }

    public function disable(string $connectionId): void
    {
        $this->authorizeAdmin();

        // The organization is in the QUERY, not in an `if` after it. `Connections::byId()`
        // resolves on the primary key alone, and this used to fetch that way and compare
        // the owner afterwards — the same shape that shipped a cross-organization IDOR on
        // /governance/{campaign}. 404 rather than a silent return: another tenant's
        // provider is not a button this administrator is failing to press, it is a row
        // they have no business learning exists.
        $connection = Connection::query()
            ->whereKey($connectionId)
            ->where('organization_id', $this->requireOrgId())
            ->first();

        abort_if($connection === null, 404);

        $name = $connection->name;
        $connection->delete();

        session()->flash('status', $name.' is no longer offered. Anyone who signed in with it keeps their account and can still use their password.');
    }

    /** @return array<string, mixed> */
    public function with(Connections $connections): array
    {
        $enabled = $connections->catalogueProvidersFor($this->orgId());
        $enabledKeys = array_map(static fn ($c): ?string => $c->provider, $enabled);

        return [
            'enabled' => $enabled,
            // The providers that can be used for SIGN-IN, asked for by name rather than
            // taken as the whole catalogue. Today those are the same set, and the day
            // they stop being — a catalogue entry that is only ever a directory — this
            // page would otherwise offer a sign-in button pointing nowhere. The question
            // this screen is actually asking is the one it should be putting.
            'available' => array_values(array_filter(
                ProviderCatalog::withCapability(ProviderCapability::Login),
                static fn (ProviderTemplate $t): bool => ! in_array($t->key, $enabledKeys, true),
            )),
            'template' => $this->selected === null ? null : ProviderCatalog::find($this->selected),
        ];
    }

    /**
     * Whether this provider hands out key material instead of a client secret.
     *
     * Asked in three places — validation, what gets stored, and what the form draws — so
     * it is one question with one answer rather than three `=== 'signed_jwt'` string
     * comparisons that could drift apart. Which they had: the form relabelled the secret
     * field for Apple while validation still demanded it and the save path still stored
     * whatever was typed as `client_secret`.
     */
    public function mintsItsOwnSecret(ProviderTemplate $template): bool
    {
        return $template->secretKind === ClientSecretKind::SignedJwt;
    }

    /**
     * The URI the administrator registers with the provider.
     *
     * Computed from the host rather than stored, and shown BEFORE the credential fields,
     * because a mismatch here is the most common way any of these fails — and the error
     * a provider returns for it names its own client id, not the URI, so it reads as a
     * credential problem.
     */
    public function redirectUriFor(ProviderTemplate $template): string
    {
        return $template->isOidc()
            ? url('/sso/oidc/{connection}/callback')
            : url('/sso/oauth2/{connection}/callback');
    }

    /**
     * The same URI for a connection that now EXISTS, with its real id in place.
     *
     * Setting one of these up is unavoidably two visits to the provider: the URI contains
     * the connection id, and the connection cannot be created before its credentials are
     * saved. The setup panel can therefore only show a `{connection}` placeholder — so
     * this is where an administrator gets the value they actually have to register, and
     * it has to stay visible, because the provider's error for a mismatch names its own
     * client id rather than the URI and reads as a credential problem.
     */
    public function callbackUriFor(Connection $connection): string
    {
        return $connection->type === ConnectionType::OAuth2
            ? url('/sso/oauth2/'.$connection->id.'/callback')
            : url('/sso/oidc/'.$connection->id.'/callback');
    }

    /**
     * The organization whose sign-in page this is about, or '' when an environment
     * administrator has not chosen one.
     *
     * Empty rather than a refusal on the READ path so the page renders and the picker in
     * the console header is reachable. Writes cannot slip through on it: every mutating
     * action calls requireOrganizationId() below.
     */
    private function orgId(): string
    {
        return app(ConsoleScope::class)->organizationId() ?? '';
    }

    /** The organization to WRITE to, or a refusal. */
    private function requireOrgId(): string
    {
        return app(ConsoleScope::class)->requireOrganizationId();
    }

    /**
     * Through ConsoleScope, so this is the SAME page on both planes.
     *
     * It read CurrentUser::isAdmin() — a question only the organization plane can answer
     * — which is why this capability shipped reachable from one console only. The person
     * who owns the environment could not reach the feature at all without impersonating
     * one of their own users.
     */
    private function authorizeAdmin(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }
}; ?>

<div>
    <x-page-header title="Social sign-in" :help="\App\Platform\Help\HelpTopic::SocialSignIn"
                   subtitle="Let people sign in with an account they already have. You supply the credentials from your own account with each provider; everything else is filled in for you." />

    @if (session('status'))
        <div class="card p-4 mb-5" style="border-color:color-mix(in srgb, var(--accent) 40%, transparent);background:var(--accent-soft)">
            <p class="text-sm" style="color:var(--accent-strong)">{{ session('status') }}</p>
        </div>
    @endif

    {{-- What people see today ---------------------------------------------- --}}
    <section class="card mb-5">
        <div class="p-4 border-b" style="border-color:var(--border)">
            <h2 class="font-semibold text-sm" style="color:var(--foreground)">On your sign-in page</h2>
            <p class="text-sm mt-0.5" style="color:var(--muted)">These appear as buttons, in this order.</p>
        </div>

        @if (count($enabled) === 0)
            <div class="px-4 py-8 text-center">
                <p class="text-sm" style="color:var(--muted)">
                    No social providers yet — people sign in with a password, a magic link or a passkey.
                </p>
                <p class="mt-1 text-sm" style="color:var(--muted)">Add one below to offer it as a button.</p>
            </div>
        @else
            <ul>
                @foreach ($enabled as $connection)
                    <li wire:key="enabled-{{ $connection->id }}" class="flex items-center gap-3 px-4 py-3.5 border-b last:border-b-0" style="border-color:var(--border)">
                        <x-provider-mark :provider="$connection->provider" :size="20" />
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-sm truncate" style="color:var(--foreground)">{{ $connection->name }}</p>
                            <p class="text-xs truncate" style="color:var(--muted)">
                                {{ $connection->type->value === 'oauth2' ? 'OAuth 2.0' : 'OpenID Connect' }}
                            </p>
                            {{-- The REAL redirect URI, which only exists once the connection does.
                                 The setup panel can only show a {connection} placeholder, so
                                 without this the one value the provider must be given is not
                                 available anywhere after saving — and the sign-in fails with an
                                 error naming the client id rather than the URI. --}}
                            <p class="mt-1.5 mono text-xs break-all" style="color:var(--muted)">
                                <span style="color:var(--foreground)">Redirect URI:</span>
                                {{ $this->callbackUriFor($connection) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="disable('{{ $connection->id }}')"
                            wire:confirm="Remove {{ $connection->name }} from your sign-in page? People who signed in with it keep their accounts."
                            class="btn btn-ghost btn-sm">Remove</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Setup ---------------------------------------------------------------- --}}
    @if ($template)
        <section class="card mb-5">
            <div class="p-4 border-b flex items-center gap-2.5" style="border-color:var(--border)">
                <x-provider-mark :provider="$template->key" :size="22" />
                <div class="min-w-0">
                    <h2 class="font-semibold text-sm" style="color:var(--foreground)">Set up {{ $template->name }}</h2>
                    <p class="text-sm mt-0.5" style="color:var(--muted)">
                        {{ $template->isOidc() ? 'OpenID Connect' : 'OAuth 2.0' }}
                        @if ($template->documentationUrl)
                            · <a href="{{ $template->documentationUrl }}" target="_blank" rel="noopener noreferrer"
                                 class="underline underline-offset-2" style="color:var(--accent-strong)">{{ $template->name }}&rsquo;s own guide ↗</a>
                        @endif
                    </p>
                </div>
            </div>

            <div class="p-4 space-y-6">
                {{-- First, because a mismatch here is how most of these fail — and the
                     error the provider returns for it names its client id, not the URI,
                     so it reads as a credential problem and gets debugged as one. --}}
                <div>
                    <p class="text-sm font-semibold" style="color:var(--foreground)">1. Register this redirect URI</p>
                    <p class="mt-1 text-sm" style="color:var(--muted)">
                        {{ $template->name }} refuses the sign-in if this does not match exactly. Replace
                        <span class="mono">{connection}</span> with the id shown after you enable it.
                    </p>
                    <p class="mt-2 mono text-xs break-all rounded-lg px-3 py-2" style="background:var(--surface-2);border:1px solid var(--border)">{{ $this->redirectUriFor($template) }}</p>
                </div>

                @if ($template->setupSteps !== [])
                    <div>
                        <p class="text-sm font-semibold" style="color:var(--foreground)">2. In {{ $template->name }}</p>
                        <ol class="mt-2 space-y-1.5 text-sm list-decimal ps-5" style="color:var(--muted)">
                            @foreach ($template->setupSteps as $step)
                                <li wire:key="step-{{ $loop->index }}">{{ $step }}</li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                <div class="space-y-4">
                    <p class="text-sm font-semibold" style="color:var(--foreground)">3. Paste what {{ $template->name }} gave you</p>

                    @foreach ($template->parameters as $parameter)
                        <div wire:key="param-{{ $parameter->key }}">
                            <label class="label" for="param-{{ $parameter->key }}">{{ $parameter->label }}</label>
                            @if (str_contains(mb_strtolower($parameter->label), 'key') && str_contains(mb_strtolower($parameter->label), 'private'))
                                <textarea id="param-{{ $parameter->key }}" rows="4" wire:model="parameters.{{ $parameter->key }}"
                                          class="input mono text-xs" placeholder="{{ $parameter->example }}"></textarea>
                            @else
                                <input id="param-{{ $parameter->key }}" type="text" wire:model="parameters.{{ $parameter->key }}"
                                       class="input" placeholder="{{ $parameter->example }}">
                            @endif
                            @if ($parameter->help)
                                <p class="mt-1 text-xs" style="color:var(--muted)">{{ $parameter->help }}</p>
                            @endif
                            @error('parameters.'.$parameter->key) <p class="field-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    @endforeach

                    <div>
                        {{-- Apple's client id is the SERVICES ID, not the App ID, and saying
                             "Client ID" here is the single most common way a first attempt
                             fails: both exist in Apple's console, both look like a reverse
                             domain, and only one of them works. --}}
                        <label class="label" for="clientId">{{ $this->mintsItsOwnSecret($template) ? 'Services ID' : 'Client ID' }}</label>
                        <input id="clientId" type="text" wire:model="clientId" class="input">
                        @if ($this->mintsItsOwnSecret($template))
                            <p class="mt-1 text-xs" style="color:var(--muted)">
                                The Services ID you enabled Sign in with Apple on — not the App ID.
                            </p>
                        @endif
                        @error('clientId') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    {{-- ASKED FOR ONLY WHERE ONE EXISTS. Apple issues no client secret: the
                         credential is an ES256 assertion minted per request from the key
                         above. The field used to be shown anyway, relabelled, which left
                         the form asking twice for the Services ID — once as the client id
                         and once as a password — and made enabling Apple impossible to get
                         right. --}}
                    @if ($this->mintsItsOwnSecret($template))
                        <p class="text-sm rounded-lg px-3 py-2.5" style="background:var(--surface-2);border:1px solid var(--border);color:var(--muted)">
                            There is no client secret to paste. {{ $template->name }} expects a signed
                            assertion instead, which we mint from the key above for each sign-in and
                            renew long before it expires.
                        </p>
                    @else
                        <div>
                            <label class="label" for="clientSecret">Client secret</label>
                            <input id="clientSecret" type="password" wire:model="clientSecret" class="input" autocomplete="off">
                            @error('clientSecret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                <div class="flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="cancel" class="btn btn-ghost">Cancel</button>
                    <button type="button" wire:click="enable" class="btn btn-primary" wire:loading.attr="disabled" wire:target="enable">
                        <span wire:loading.remove wire:target="enable">Enable {{ $template->name }}</span>
                        <span wire:loading wire:target="enable">Checking with {{ $template->name }}&hellip;</span>
                    </button>
                </div>
            </div>
        </section>
    @endif

    {{-- The catalogue -------------------------------------------------------- --}}
    @if (count($available) > 0)
        <section class="card">
            <div class="p-4 border-b" style="border-color:var(--border)">
                <h2 class="font-semibold text-sm" style="color:var(--foreground)">Add a provider</h2>
                <p class="text-sm mt-0.5" style="color:var(--muted)">
                    You will need a client ID and secret from your own account with the provider.
                </p>
            </div>

            <div class="p-4 grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($available as $option)
                    <button
                        type="button"
                        wire:key="option-{{ $option->key }}"
                        wire:click="choose('{{ $option->key }}')"
                        aria-pressed="{{ $selected === $option->key ? 'true' : 'false' }}"
                        class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-start transition"
                        style="border:1px solid {{ $selected === $option->key ? 'var(--accent)' : 'var(--border)' }};background:{{ $selected === $option->key ? 'var(--accent-soft)' : 'transparent' }}">
                        <x-provider-mark :provider="$option->key" :size="20" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-sm truncate" style="color:var(--foreground)">{{ $option->name }}</span>
                            <span class="block text-xs truncate" style="color:var(--muted)">{{ $option->isOidc() ? 'OpenID Connect' : 'OAuth 2.0' }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </section>
    @endif
</div>
