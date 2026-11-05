<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Apps\SetAppApiKeyPrefix;
use App\Actions\Apps\SetAppBackchannelLogout;
use App\Actions\Apps\SetAppTokenExchange;
use App\Actions\Apps\SetAppTokenLifetime;
use App\Http\Requests\Console\SaveApiKeyPrefixRequest;
use App\Http\Requests\Console\SaveBackchannelLogoutRequest;
use App\Http\Requests\Console\SaveTokenExchangeRequest;
use App\Http\Requests\Console\SaveTokenLifetimeRequest;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionRefused;
use App\Platform\Console\AppHeader;
use App\Platform\Console\AppTabs;
use App\Platform\Console\ConsoleClients;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantType;
use Cbox\Id\OAuthServer\Support\AccessTokenLifetime;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * AN APP › SETTINGS — how long its tokens live, whether it may exchange them, where it is
 * told somebody signed out, and whether the people using it may create API keys for it.
 *
 * FOUR FORMS, FOUR WRITES, FOUR ACTIONS (`apps.settings.*`, the same the management API
 * runs). Each saves one setting through the registry, built from the app's own blueprint
 * with that one setting replaced ({@see ClientRegistry::update()}), so a form never writes
 * back a value another form changed since this page loaded — and each change is one
 * `app.updated` entry naming exactly what it changed.
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

    public function lifetime(SaveTokenLifetimeRequest $request, string $client): RedirectResponse
    {
        $model = $this->clients->manageable($client);

        return $this->save(
            SetAppTokenLifetime::class,
            ['id' => $model->id, 'access_token_ttl' => $request->seconds()],
            'minutes',
            $request->seconds() === null
                ? __('console.access_tokens_for_this_app_now_live_for_the__29920738')
                : __('console.access_tokens_for_this_app_now_live_for_c5b4321a').intdiv((int) $request->seconds(), 60).' minutes.',
        );
    }

    public function exchange(SaveTokenExchangeRequest $request, string $client): RedirectResponse
    {
        $model = $this->clients->manageable($client);

        // Said before the action is asked, on the switch and without the form's input —
        // the action refuses the same app in the same words, which is the guard.
        if ($model->type !== ClientType::Confidential) {
            return back()->withErrors(['enabled' => __('console.only_an_app_that_holds_a_secret_or_its_own_k_b8912c8c')]);
        }

        return $this->save(
            SetAppTokenExchange::class,
            ['id' => $model->id, 'enabled' => $request->enabled()],
            'enabled',
            $request->enabled() ? __('console.token_exchange_turned_on_e991b0e0') : __('console.token_exchange_turned_off_1cb60f42'),
        );
    }

    public function logout(SaveBackchannelLogoutRequest $request, string $client): RedirectResponse
    {
        $model = $this->clients->manageable($client);

        return $this->save(
            SetAppBackchannelLogout::class,
            ['id' => $model->id, 'uri' => $request->logoutUri(), 'session_required' => $request->sessionRequired()],
            'uri',
            $request->logoutUri() === null
                ? __('console.this_app_is_no_longer_told_when_somebody_sig_f18570ba')
                : __('console.saved_this_app_is_told_when_somebody_signs_o_d0d7f618'),
        );
    }

    public function apiKeys(SaveApiKeyPrefixRequest $request, string $client): RedirectResponse
    {
        $model = $this->clients->manageable($client);
        $prefix = $request->prefix();

        // A prefix another app here already uses is refused by the action, in a sentence
        // (`api_key_prefix_taken`), before the registry would refuse it under its index.
        return $this->save(
            SetAppApiKeyPrefix::class,
            ['id' => $model->id, 'prefix' => $prefix],
            'prefix',
            $prefix === null
                ? __('console.api_keys_turned_off_for_this_app_keys_alread_d0c591df')
                : "API keys turned on for this app. New keys start with {$prefix}_.",
        );
    }

    /**
     * One setting, saved through its action; a refusal lands on the field that asked.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     */
    private function save(string $action, array $input, string $field, string $status): RedirectResponse
    {
        $result = $this->attempt($action, $input, fallback: $field);

        if ($result instanceof ActionRefused) {
            return back()->withInput()->withErrors([$field => self::plain($result->getMessage())]);
        }

        return back()->with('status', $status);
    }

    /**
     * The registry's refusal with its field names said the way this page says them.
     */
    private static function plain(string $message): string
    {
        $message = strtr($message, [
            'backchannel_logout_uri' => __('console.the_logout_uri_ec20fe60'),
            'access_token_ttl' => __('console.the_access_token_lifetime_15671af5'),
            'api_key_prefix' => __('console.the_key_prefix_29613943'),
        ]);

        // "…is already declared by another app in this environment; import with another
        // prefix (or none)" — an import is not what happened on this page.
        if (str_contains($message, __('console.already_declared_by_another_app_35fc0ae9'))) {
            return __('console.another_app_in_this_environment_already_uses_e8f83cff');
        }

        return ucfirst($message);
    }
}
