<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Controllers\Console\ClientPromotionController;
use App\Http\Props\Console\AppCopyProps;
use App\Http\Props\Console\AppHeaderProps;
use App\Http\Props\Console\CopyTargetProps;
use App\Platform\AppKind;
use App\Platform\Apps\CopyTargets;
use App\Platform\Locale\ConsoleStrings;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Environment;

/**
 * The header every tab of an app's page shares — built in one place, so the four tabs
 * cannot disagree about the app's name, its tabs, or whether it can be copied.
 */
final readonly class AppHeader
{
    public function __construct(
        private ConsoleScope $scope,
        private ConsoleClients $clients,
        private AppTabs $tabs,
        private CopyTargets $targets,
    ) {}

    public function for(Client $client, string $tab): AppHeaderProps
    {
        $manages = $this->clients->mayManage($client);

        return new AppHeaderProps(
            id: $client->id,
            name: $client->name,
            clientId: $client->client_id,
            confidential: $client->type === ClientType::Confidential,
            firstParty: $client->first_party,
            kindLabel: AppKind::forClient($client)->label(),
            tabs: $this->tabs->for($client, $tab),
            indexHref: route($this->scope->routeName('clients')),
            blueprintHref: $manages ? route($this->scope->routeName('clients.blueprint'), $client->id) : null,
            copy: $this->scope->plane() === ConsolePlane::Environment ? $this->copy($client) : null,
        );
    }

    /**
     * Why this app cannot be copied into another environment, or null when it can be.
     *
     * The same answers {@see ClientPromotionController}
     * refuses with, so the dialog and the write cannot disagree.
     */
    public static function copyRefusal(Client $client): ?string
    {
        // Organizations live in one environment. The copy would have to be owned by the
        // target environment instead — and an app the environment owns may hold any scope
        // and, marked first-party, skips every organization's consent screen. Promoting an
        // organization's app into that is a change of what it is, not a copy of it.
        if ($client->organization_id !== null) {
            return ConsoleStrings::message('console.this_app_belongs_to_an_organization_and_an_o_5487ac51');
        }

        // Registered through RFC 7591 by whoever reached the registration endpoint, so it
        // is not the environment's own app — a copy made here would be.
        if ($client->isDynamicallyRegistered()) {
            return ConsoleStrings::message('console.this_app_registered_itself_so_it_is_not_one__ec7cea33');
        }

        if ($client->type === ClientType::Confidential && ! AppTabs::holdsSharedSecret($client)) {
            return ConsoleStrings::message('console.this_app_signs_in_with_its_own_keys_and_each_a40a0c9b');
        }

        return null;
    }

    private function copy(Client $client): AppCopyProps
    {
        $targets = $this->targets->all();
        $refusal = self::copyRefusal($client);

        if ($refusal === null && $targets === []) {
            $refusal = ConsoleStrings::message('console.there_is_no_other_environment_in_this_projec_bfd62f34');
        }

        return new AppCopyProps(
            href: route($this->scope->routeName('clients.copy'), $client->id),
            targets: array_map(static fn (Environment $environment): CopyTargetProps => CopyTargetProps::of($environment), $targets),
            redirectUris: array_values($client->redirect_uris),
            unavailable: $refusal,
        );
    }
}
