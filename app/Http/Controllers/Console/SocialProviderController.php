<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\EnableSocialProviderRequest;
use App\Platform\Console\ConsoleScope;
use App\Platform\Help\HelpTopic;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Throwable;

/**
 * SOCIAL SIGN-IN — pick a provider from a list instead of describing one from memory.
 *
 * Everything an administrator used to have to know — Google's issuer, that Entra's names
 * the directory, that GitHub is not an OpenID Provider at all, which scopes carry an
 * address — is catalogue data. What is left is the part that genuinely is theirs: the
 * client id and secret from their own account with that provider.
 *
 * The screen is built around the two things that actually go wrong. The redirect URI is
 * shown before anything else and is copyable, because "the redirect URI does not match" is
 * the single most common failure setting any of these up. And the provider's own steps are
 * shown beside the fields rather than linked away to, because the person filling this in is
 * switching between two browser tabs and every extra one costs them their place.
 *
 * ONE PAGE, BOTH PLANES, through {@see ConsoleScope}. It used to ask
 * `CurrentUser::isAdmin()` — a question only the organization plane can answer — which is
 * why this capability shipped reachable from one console only: the person who owns the
 * environment could not reach the feature at all without impersonating one of their users.
 */
final readonly class SocialProviderController extends ConsoleController
{
    public function index(Request $request, Connections $connections): Response
    {
        $this->scope->assertMayAdminister();

        /*
         * Empty rather than a refusal on the READ path, so the page renders and the
         * acting-organization picker in the console header is reachable. Writes cannot slip
         * through on it: every one of them calls `requireOrganizationId()`.
         */
        $organizationId = $this->scope->organizationId() ?? '';

        $enabled = $connections->catalogueProvidersFor($organizationId);
        $enabledKeys = array_map(static fn (Connection $connection): ?string => $connection->provider, $enabled);

        /*
         * WHICH PROVIDER IS BEING SET UP, in the URL rather than in component state. It was a
         * locked property before, which meant the setup panel could not be linked to, shared
         * or reloaded — and a person following a provider's own documentation in a second tab
         * is exactly the person who reloads.
         */
        $template = $this->loginTemplate($request->string('provider')->toString());

        return $this->page('console/social-providers', 'Social sign-in', [
            'enabled' => array_map(fn (Connection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'provider' => $connection->provider,
                'protocol' => $connection->type === ConnectionType::OAuth2 ? 'OAuth 2.0' : 'OpenID Connect',
                /*
                 * THE REAL REDIRECT URI, which only exists once the connection does. The
                 * setup panel can only show a `{connection}` placeholder, so without this the
                 * one value the provider must be given is available nowhere after saving —
                 * and the sign-in then fails with an error naming the client id rather than
                 * the URI, which reads as a credential problem and gets debugged as one.
                 */
                'callbackUri' => $this->callbackUriFor($connection),
                'removeHref' => $this->url('social-providers.destroy', $connection->id),
            ], $enabled),
            /*
             * The providers that can be used for SIGN-IN, asked for by name rather than taken
             * as the whole catalogue. Today those are the same set, and the day they stop
             * being — a catalogue entry that is only ever a directory — this page would
             * otherwise offer a sign-in button pointing nowhere.
             */
            'available' => array_map(fn (ProviderTemplate $option): array => [
                'key' => $option->key,
                'name' => $option->name,
                'protocol' => $option->isOidc() ? 'OpenID Connect' : 'OAuth 2.0',
                'href' => $this->url('social-providers', ['provider' => $option->key]),
            ], array_values(array_filter(
                ProviderCatalog::withCapability(ProviderCapability::Login),
                static fn (ProviderTemplate $t): bool => ! in_array($t->key, $enabledKeys, true),
            ))),
            'template' => $template === null ? null : $this->templateProps($template),
            'indexHref' => $this->url('social-providers'),
            'storeHref' => $this->url('social-providers.store'),
            'help' => HelpProps::for(HelpTopic::SocialSignIn),
        ]);
    }

    public function store(
        EnableSocialProviderRequest $request,
        Connections $connections,
        OidcDiscovery $discovery,
    ): RedirectResponse {
        $this->scope->assertMayAdminister();
        app(VerifiedEmailGate::class)->require('add a sign-in provider');

        $template = $this->loginTemplate($request->provider());

        if ($template === null) {
            return back()->withErrors(['provider' => 'That provider is not one this console can offer.']);
        }

        $secretRequired = ! $this->mintsItsOwnSecret($template);

        if ($secretRequired && $request->clientSecret() === '') {
            return back()->withInput()->withErrors(['clientSecret' => 'The client secret is required.']);
        }

        // EVERY MISSING PARAMETER AT ONCE. Reporting the first and stopping would walk
        // somebody through Apple's three fields one round-trip at a time.
        $values = $request->parameters();
        $missing = [];

        foreach ($template->parameters as $parameter) {
            if (($values[$parameter->key] ?? '') === '') {
                $missing['parameters.'.$parameter->key] = $parameter->label.' is required.';
            }
        }

        if ($missing !== []) {
            return back()->withInput()->withErrors($missing);
        }

        $organizationId = $this->scope->requireOrganizationId();

        // One connection per provider per tenant. Two would each render a button with the
        // same name, and nothing on the sign-in page could tell a person which to press.
        foreach ($connections->catalogueProvidersFor($organizationId) as $existing) {
            if ($existing->provider === $template->key) {
                return back()->withInput()->withErrors([
                    'clientId' => $template->name.' is already enabled. Remove it first if you want to use different credentials.',
                ]);
            }
        }

        $config = [
            'provider' => $template->key,
            'client_id' => $request->clientId(),
            ...($secretRequired ? ['client_secret' => $request->clientSecret()] : []),
            ...$values,
        ];

        if ($template->isOidc()) {
            $issuer = $template->issuerFor($values);

            if ($issuer === null) {
                return back()->withInput()->withErrors([
                    'clientId' => 'Fill in every field above before enabling '.$template->name.'.',
                ]);
            }

            $config['issuer'] = $issuer;

            try {
                /*
                 * DISCOVERY NOW, not at somebody's first sign-in. An OIDC entry is cheap to
                 * get wrong safely precisely because this runs here: a mistyped Okta domain
                 * fails with the provider's own error while the administrator is still
                 * looking at the form, rather than silently, later, for a user.
                 */
                $document = $discovery->fromIssuer($issuer);
                $config['authorization_endpoint'] = $document->authorizationEndpoint;
                $config['token_endpoint'] = $document->tokenEndpoint;
                $config['jwks_uri'] = $document->jwksUri;
            } catch (Throwable $e) {
                return back()->withInput()->withErrors([
                    'clientId' => 'We could not reach '.$template->name.' at '.$issuer.' — check the details above. ('.$e->getMessage().')',
                ]);
            }
        }

        try {
            $connection = $connections->create(
                organizationId: $organizationId,
                type: $template->isOidc() ? ConnectionType::Oidc : ConnectionType::OAuth2,
                name: $template->name,
                config: $config,
                provider: $template->key,
            );
        } catch (InvalidAssertion $e) {
            return back()->withInput()->withErrors(['clientId' => $e->getMessage()]);
        }

        // Created as a draft, then activated: a half-saved provider must never appear as a
        // button on the sign-in page while somebody is still typing.
        $connections->activate($organizationId, $connection->id);

        return to_route($this->scope->routeName('social-providers'))
            ->with('status', $template->name.' is now offered on your sign-in page.');
    }

    public function destroy(string $connection): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * THE ORGANIZATION IS IN THE QUERY, not in an `if` after it. `Connections::byId()`
         * resolves on the primary key alone, and this used to fetch that way and compare the
         * owner afterwards — the same shape that shipped a cross-organization IDOR on
         * `/governance/{campaign}`. 404 rather than a silent return: another tenant's
         * provider is not a button this administrator is failing to press, it is a row they
         * have no business learning exists.
         */
        $model = Connection::query()
            ->whereKey($connection)
            ->where('organization_id', $this->scope->requireOrganizationId())
            ->first();

        abort_if($model === null, 404);

        $name = $model->name;
        $model->delete();

        return back()->with(
            'status',
            $name.' is no longer offered. Anyone who signed in with it keeps their account and can still use their password.',
        );
    }

    /**
     * A catalogue entry that can actually be used to sign somebody in, or null.
     *
     * The key arrives from the browser and everything downstream is looked up by it. Being
     * IN the catalogue is not the same question as being usable here: an entry can carry a
     * directory and no sign-in half, and this page would then build a connection out of
     * endpoints it does not have. Deny-by-default, in the one place the key crosses in.
     */
    private function loginTemplate(string $key): ?ProviderTemplate
    {
        if ($key === '') {
            return null;
        }

        $template = ProviderCatalog::find($key);

        return $template?->supports(ProviderCapability::Login) === true ? $template : null;
    }

    /**
     * Whether this provider hands out key material instead of a client secret.
     *
     * Asked in three places — validation, what gets stored, and what the form draws — so it
     * is one question with one answer rather than three `=== 'signed_jwt'` comparisons that
     * could drift apart. Which they had: the form relabelled the secret field for Apple
     * while validation still demanded it and the save path stored whatever was typed.
     */
    private function mintsItsOwnSecret(ProviderTemplate $template): bool
    {
        return $template->secretKind === ClientSecretKind::SignedJwt;
    }

    /**
     * The URI the administrator registers with the provider, before the connection exists.
     *
     * Computed from the host rather than stored, and shown BEFORE the credential fields,
     * because a mismatch here is the most common way any of these fails — and the error a
     * provider returns for it names its own client id, not the URI.
     */
    private function redirectUriFor(ProviderTemplate $template): string
    {
        return $template->isOidc()
            ? url('/sso/oidc/{connection}/callback')
            : url('/sso/oauth2/{connection}/callback');
    }

    /** The same URI for a connection that now EXISTS, with its real id in place. */
    private function callbackUriFor(Connection $connection): string
    {
        return $connection->type === ConnectionType::OAuth2
            ? url('/sso/oauth2/'.$connection->id.'/callback')
            : url('/sso/oidc/'.$connection->id.'/callback');
    }

    /**
     * @return array<string, mixed>
     */
    private function templateProps(ProviderTemplate $template): array
    {
        return [
            'key' => $template->key,
            'name' => $template->name,
            'protocol' => $template->isOidc() ? 'OpenID Connect' : 'OAuth 2.0',
            'documentationUrl' => $template->documentationUrl,
            'redirectUri' => $this->redirectUriFor($template),
            'setupSteps' => $template->setupSteps,
            'parameters' => array_map(static fn ($parameter): array => [
                'key' => $parameter->key,
                'label' => $parameter->label,
                'help' => $parameter->help,
                'example' => $parameter->example,
                // A PEM key is four lines, not a word: the form asks for it in a textarea.
                // Decided here rather than by the component sniffing the label for "private".
                'multiline' => str_contains(mb_strtolower($parameter->label), 'key')
                    && str_contains(mb_strtolower($parameter->label), 'private'),
            ], $template->parameters),
            /*
             * Apple's client id is the SERVICES ID, not the App ID, and saying "Client ID"
             * here is the single most common way a first attempt fails: both exist in Apple's
             * console, both look like a reverse domain, and only one of them works.
             */
            'mintsItsOwnSecret' => $this->mintsItsOwnSecret($template),
        ];
    }
}
