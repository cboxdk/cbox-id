<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\SignIn\EnableSocialProvider;
use App\Actions\SignIn\RemoveSocialProvider;
use App\Actions\SignIn\SocialProviderFields;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\EnableSocialProviderRequest;
use App\Platform\Console\ConsoleScope;
use App\Platform\Help\HelpTopic;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ClientSecretKind;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Enums\ProviderCapability;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

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
 *
 * Enabling and removing are ACTIONS (`signin.social.set`, `signin.social.delete`), the same
 * classes the management API runs; this controller maps the form onto them.
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
        $template = SocialProviderFields::loginTemplate($request->string('provider')->toString());

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

    /**
     * Enable a provider through the ACTION the management API runs ({@see EnableSocialProvider}):
     * the catalogue checks, the one-per-provider rule and discovery are the action's, so a
     * provider enabled here and one enabled by a key are refused and recorded alike.
     */
    public function store(EnableSocialProviderRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();
        app(VerifiedEmailGate::class)->require('add a sign-in provider');

        $template = SocialProviderFields::loginTemplate($request->provider());
        $parameters = $template === null ? [] : array_map(static fn ($parameter): string => $parameter->key, $template->parameters);

        // A refusal about a parameter lands on that parameter's own field.
        $fields = ['provider' => 'provider', 'client_id' => 'clientId', 'client_secret' => 'clientSecret'];

        foreach ($parameters as $key) {
            $fields['parameters.'.$key] = 'parameters.'.$key;
        }

        $result = $this->act(EnableSocialProvider::class, [
            'organization_id' => $this->scope->requireOrganizationId(),
            'provider' => $request->provider(),
            'client_id' => $request->clientId(),
            'client_secret' => $request->clientSecret(),
            'parameters' => array_intersect_key($request->parameters(), array_flip($parameters)),
        ], $fields, 'clientId');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Connection $enabled */
        $enabled = $result->value;

        return to_route($this->scope->routeName('social-providers'))
            ->with('status', $enabled->name.' is now offered on your sign-in page.');
    }

    public function destroy(string $connection): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * THE ORGANIZATION IS IN THE QUERY — the action's — not in an `if` after it.
         * Another tenant's provider is a 404, not a button this administrator is failing to
         * press: it is a row they have no business learning exists.
         */
        $result = $this->act(RemoveSocialProvider::class, [
            'id' => $connection,
            'organization_id' => $this->scope->requireOrganizationId(),
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Connection $removed */
        $removed = $result->value;

        return back()->with(
            'status',
            $removed->name.' is no longer offered. Anyone who signed in with it keeps their account and can still use their password.',
        );
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
