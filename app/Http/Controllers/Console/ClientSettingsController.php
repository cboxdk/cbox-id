<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\SaveApiKeyPrefixRequest;
use App\Http\Requests\Console\SaveBackchannelLogoutRequest;
use App\Http\Requests\Console\SaveTokenExchangeRequest;
use App\Http\Requests\Console\SaveTokenLifetimeRequest;
use App\Platform\Console\AppHeader;
use App\Platform\Console\AppTabs;
use App\Platform\Console\ConsoleClients;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantType;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Support\AccessTokenLifetime;
use Cbox\Id\OAuthServer\ValueObjects\ClientBlueprint;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * AN APP › SETTINGS — how long its tokens live, whether it may exchange them, where it is
 * told somebody signed out, and whether the people using it may create API keys for it.
 *
 * FOUR FORMS, FOUR WRITES. Each saves one setting through the registry, built from the
 * app's own blueprint with that one setting replaced ({@see ClientRegistry::update()}), so
 * a form never writes back a value another form changed since this page loaded — and each
 * change is one `app.updated` entry naming exactly what it changed.
 *
 * A page of changes, so it is the managers' page only: the tab is not drawn for anybody
 * else and every action here resolves the app through {@see ConsoleClients::manageable()}.
 */
final readonly class ClientSettingsController extends ConsoleController
{
    public function __construct(
        ResponseFactory $inertia,
        ConsoleScope $scope,
        private ConsoleClients $clients,
    ) {
        parent::__construct($inertia, $scope);
    }

    public function show(string $client, AppHeader $header): Response
    {
        $model = $this->clients->manageable($client);
        $confidential = $model->type === ClientType::Confidential;

        return $this->page('console/clients/settings', $model->name, [
            'appHeader' => $header->for($model, AppTabs::SETTINGS),
            'lifetime' => [
                'minutes' => $model->access_token_ttl === null ? null : intdiv($model->access_token_ttl, 60),
                'defaultMinutes' => intdiv(AccessTokenLifetime::deploymentDefault(), 60),
                'ceilingMinutes' => SaveTokenLifetimeRequest::ceilingMinutes(),
                'href' => $this->url('clients.settings.lifetime', $model->id),
            ],
            'exchange' => [
                'enabled' => in_array(GrantType::TokenExchange->value, $model->grant_types, true),
                // Only an app that can prove who it is may trade one token for another:
                // the token endpoint requires client authentication for the grant, so a
                // public app offered the switch would be offered a grant that is refused
                // on every call.
                'available' => $confidential,
                'href' => $this->url('clients.settings.exchange', $model->id),
            ],
            'logout' => [
                'uri' => $model->backchannel_logout_uri ?? '',
                'sessionRequired' => $model->backchannel_logout_session_required,
                'href' => $this->url('clients.settings.logout', $model->id),
            ],
            'apiKeys' => [
                'prefix' => $model->api_key_prefix ?? '',
                'href' => $this->url('clients.settings.api-keys', $model->id),
            ],
        ]);
    }

    public function lifetime(SaveTokenLifetimeRequest $request, string $client, ClientRegistry $registry): RedirectResponse
    {
        $model = $this->clients->manageable($client);

        return $this->save(
            $model,
            $registry,
            $registry->blueprint($model)->withAccessTokenTtl($request->seconds()),
            'minutes',
            $request->seconds() === null
                ? 'Access tokens for this app now live for the default time.'
                : 'Access tokens for this app now live for '.intdiv((int) $request->seconds(), 60).' minutes.',
        );
    }

    public function exchange(SaveTokenExchangeRequest $request, string $client, ClientRegistry $registry): RedirectResponse
    {
        $model = $this->clients->manageable($client);

        if ($model->type !== ClientType::Confidential) {
            return back()->withErrors(['enabled' => 'Only an app that holds a secret or its own keys can exchange tokens. A public app cannot prove who is asking.']);
        }

        $grants = array_values(array_filter(
            $model->grant_types,
            static fn (string $grant): bool => $grant !== GrantType::TokenExchange->value,
        ));

        if ($request->enabled()) {
            $grants[] = GrantType::TokenExchange->value;
        }

        return $this->save(
            $model,
            $registry,
            $registry->blueprint($model)->withGrantTypes($grants),
            'enabled',
            $request->enabled() ? 'Token exchange turned on.' : 'Token exchange turned off.',
        );
    }

    public function logout(SaveBackchannelLogoutRequest $request, string $client, ClientRegistry $registry): RedirectResponse
    {
        $model = $this->clients->manageable($client);

        return $this->save(
            $model,
            $registry,
            $registry->blueprint($model)->withBackchannelLogout($request->logoutUri(), $request->sessionRequired()),
            'uri',
            $request->logoutUri() === null
                ? 'This app is no longer told when somebody signs out.'
                : 'Saved. This app is told when somebody signs out.',
        );
    }

    public function apiKeys(SaveApiKeyPrefixRequest $request, string $client, ClientRegistry $registry): RedirectResponse
    {
        $model = $this->clients->manageable($client);
        $prefix = $request->prefix();

        // Asked here so the refusal reads as a sentence. The registry asks again under its
        // unique index, which is the guard; this is the explanation.
        if ($prefix !== null && Client::query()->where('api_key_prefix', $prefix)->whereKeyNot($model->id)->exists()) {
            return back()->withInput()->withErrors(['prefix' => 'Another app in this environment already uses this prefix. Choose another.']);
        }

        return $this->save(
            $model,
            $registry,
            $registry->blueprint($model)->withApiKeyPrefix($prefix),
            'prefix',
            $prefix === null
                ? 'User API keys turned off. Keys already created keep working until they are revoked.'
                : "User API keys turned on. New keys start with {$prefix}_.",
        );
    }

    /**
     * One setting, saved through the registry; a refusal lands on the field that asked.
     */
    private function save(Client $model, ClientRegistry $registry, ClientBlueprint $settings, string $field, string $status): RedirectResponse
    {
        try {
            $registry->update($model, $settings, $this->scope->auditActor());
        } catch (InvalidClientMetadata $refused) {
            return back()->withInput()->withErrors([$field => self::plain($refused->getMessage())]);
        }

        return back()->with('status', $status);
    }

    /**
     * The registry's refusal with its field names said the way this page says them.
     */
    private static function plain(string $message): string
    {
        $message = strtr($message, [
            'backchannel_logout_uri' => 'The logout URI',
            'access_token_ttl' => 'The access token lifetime',
            'api_key_prefix' => 'The key prefix',
        ]);

        // "…is already declared by another app in this environment; import with another
        // prefix (or none)" — an import is not what happened on this page.
        if (str_contains($message, 'already declared by another app')) {
            return 'Another app in this environment already uses this prefix. Choose another.';
        }

        return ucfirst($message);
    }
}
