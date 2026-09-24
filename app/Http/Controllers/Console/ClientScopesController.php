<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\SaveClientScopesRequest;
use App\Platform\Apps\AppScopes;
use App\Platform\Console\AppHeader;
use App\Platform\Console\AppTabs;
use App\Platform\Console\ConsoleClients;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\ScopeCatalog;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Exceptions\ScopeNotGrantable;
use Cbox\Id\OAuthServer\ValueObjects\ScopeHolder;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * AN APP › SCOPES — what the app may ask for, and the audience its tokens will carry.
 *
 * THE CEILING, and narrowing it takes effect on the next token. A device or agent request
 * naming a scope removed here is refused outright rather than downscoped, so this is a live
 * change to what an integration can ask for — which is exactly why it is a page rather than
 * something behind delete-and-recreate.
 *
 * A REGISTERED API'S SCOPES ARE ITS OWNER'S TO HAND OUT. The picker offers only those this
 * app may hold ({@see AppScopes}), and the framework refuses the rest when the app is saved
 * however they arrived — ticked in a crafted request or typed under Advanced. The refusal
 * is shown as a sentence naming the scope and why, not as a stack of scope keys.
 */
final readonly class ClientScopesController extends ConsoleController
{
    public function __construct(
        ResponseFactory $inertia,
        ConsoleScope $scope,
        private ConsoleClients $clients,
    ) {
        parent::__construct($inertia, $scope);
    }

    public function show(string $client, AppHeader $header, AppScopes $scopes, ScopeCatalog $catalog): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->clients->visible($client);
        $manages = $this->clients->mayManage($model);
        $stored = $scopes->split($model);

        return $this->page('console/clients/scopes', $model->name, [
            'appHeader' => $header->for($model, AppTabs::SCOPES),
            'stored' => [
                'catalogue' => $stored->catalogue,
                'api' => $stored->api,
                'custom' => implode(', ', $stored->custom),
                'all' => array_values($model->scopes),
            ],
            // The choices are drawn only for somebody who may make them. A tenant
            // administrator reading a platform app sees what it holds, and is not handed
            // the list of every API that app could have been given.
            'scopeGroups' => $manages ? $catalog->grouped() : [],
            'apiGroups' => $manages ? $scopes->offered(ScopeHolder::of($model)) : [],
            'audience' => $scopes->audience($model),
            'mayManage' => $manages,
            'updateHref' => $this->url('clients.scopes.update', $model->id),
            'apisHref' => $this->scope->plane() === ConsolePlane::Environment
                ? route('environment.apis')
                : null,
        ]);
    }

    public function update(SaveClientScopesRequest $request, string $client, ClientRegistry $registry, AppScopes $scopes): RedirectResponse
    {
        $model = $this->clients->manageable($client);
        $holder = ScopeHolder::of($model);

        $next = array_values(array_unique([
            ...$scopes->selectable($holder, $request->chosen()),
            ...$request->custom(),
        ]));

        try {
            $registry->update($model, $registry->blueprint($model)->withScopes($next), $this->scope->auditActor());
        } catch (ScopeNotGrantable $refused) {
            return back()->withInput()->withErrors(['scopes' => $scopes->explain($refused, $holder)]);
        } catch (InvalidClientMetadata $refused) {
            return back()->withInput()->withErrors(['scopes' => $refused->getMessage()]);
        }

        return back()->with('status', 'Scopes saved. They apply from the next token this app asks for.');
    }
}
