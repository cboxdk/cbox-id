<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Controllers\Console\ClientPromotionController;
use App\Http\Props\Console\AppCopyProps;
use App\Http\Props\Console\AppHeaderProps;
use App\Http\Props\Console\CopyTargetProps;
use App\Platform\AppKind;
use App\Platform\Apps\CopyTargets;
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
            return 'This app belongs to an organization, and an organization exists in one environment only. Download its blueprint and register it in the other environment under the organization it belongs to there.';
        }

        // Registered through RFC 7591 by whoever reached the registration endpoint, so it
        // is not the environment's own app — a copy made here would be.
        if ($client->isDynamicallyRegistered()) {
            return 'This app registered itself, so it is not one of this environment\'s own apps. It can register itself in the other environment the same way.';
        }

        if ($client->type === ClientType::Confidential && ! AppTabs::holdsSharedSecret($client)) {
            return 'This app signs in with its own keys, and each environment should hold keys of its own. Download its blueprint and register it in the other environment with that environment\'s key set.';
        }

        return null;
    }

    private function copy(Client $client): AppCopyProps
    {
        $targets = $this->targets->all();
        $refusal = self::copyRefusal($client);

        if ($refusal === null && $targets === []) {
            $refusal = 'There is no other environment in this project that you administer. Add one to the project, or ask for access to it, and it appears here.';
        }

        return new AppCopyProps(
            href: route($this->scope->routeName('clients.copy'), $client->id),
            targets: array_map(static fn (Environment $environment): CopyTargetProps => CopyTargetProps::of($environment), $targets),
            redirectUris: array_values($client->redirect_uris),
            unavailable: $refusal,
        );
    }
}
