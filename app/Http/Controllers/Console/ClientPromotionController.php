<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\CopyClientRequest;
use App\Platform\Apps\CopyTargets;
use App\Platform\Console\AppHeader;
use App\Platform\Console\ConsoleClients;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Exceptions\ScopeNotGrantable;
use Cbox\Id\OAuthServer\ValueObjects\ClientBlueprint;
use Cbox\Id\OAuthServer\ValueObjects\RegisteredClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\ResponseFactory;

/**
 * TAKING AN APP SOMEWHERE ELSE — as a file, or into another environment of this project.
 *
 * Both are built on the app's {@see ClientBlueprint}: its configuration without its
 * identity or its credentials. A client id is minted per environment and a secret is never
 * copied, so what travels is exactly what a person would otherwise re-type into a second
 * console by hand, and what does not travel is exactly what must not.
 *
 *  - DOWNLOAD BLUEPRINT, on both consoles: the JSON document, to commit beside the app's
 *    code or hand to the management API. Deterministic, so exporting twice gives the same
 *    bytes and a committed blueprint diffs cleanly.
 *  - COPY TO ANOTHER ENVIRONMENT, on the environment console only: import the blueprint as
 *    a new app in staging's production twin (or the reverse), with its own client id and
 *    secret, shown here once. An organization has no other environment to copy into.
 */
final readonly class ClientPromotionController extends ConsoleController
{
    public function __construct(
        ResponseFactory $inertia,
        ConsoleScope $scope,
        private ConsoleClients $clients,
    ) {
        parent::__construct($inertia, $scope);
    }

    /**
     * The app's blueprint as a JSON file. Never a secret, never the client id: the
     * blueprint does not carry them.
     *
     * For somebody who manages the app only. A blueprint is its whole configuration —
     * redirect URIs, scopes, the manifest URL — and this console shows that configuration
     * to whoever manages the app and to nobody else.
     */
    public function blueprint(string $client, ClientRegistry $registry): Response
    {
        $model = $this->clients->manageable($client);

        $filename = (Str::slug($model->name) ?: 'app').'.blueprint.json';

        return response($registry->blueprint($model)->toJson(), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Register this app again in another environment of the project, from its blueprint.
     */
    public function copy(
        CopyClientRequest $request,
        string $client,
        ClientRegistry $registry,
        CopyTargets $targets,
        EnvironmentContext $context,
        IssuerResolver $issuers,
    ): RedirectResponse {
        // The environment console's alone: an organization administrator has no other
        // environment, and "the same organization over there" does not exist.
        $this->scope->assertMayAdministerEnvironment();

        $model = $this->clients->manageable($client);

        $refusal = AppHeader::copyRefusal($model);

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        // Asked of the SAME predicate the dialog listed from, so a crafted id — another
        // project's environment, one this person may not reach, this one — meets exactly
        // the refusal an honest choice the list never offered would.
        $target = $targets->find($request->environment());

        if ($target === null) {
            return back()->withInput()->withErrors(['environment' => 'Choose one of the environments offered. It has to be in this project, and one you administer.']);
        }

        /*
         * LAST, after authorization and after every refusal. Copying registers an app with
         * a live secret and puts that secret on this page — the same credential a
         * registration or a rotation hands over, behind the same gate.
         */
        $sudo = app(ConsoleStepUp::class)->challenge(
            'clients.show',
            'environment.clients.show',
            ['client' => $model->id],
            'Copying an app registers it in another environment with a new client secret, shown once on the next screen.',
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $blueprint = $registry->blueprint($model)
            ->withName($request->name())
            ->withRedirectUris($request->redirectUris());

        // WHO IS ACTING is read HERE, before the environment moves. Inside `runAs()` the
        // host no longer matches the environment this admin session is anchored to, so the
        // session resolves to nobody there — by design — and the copy would be recorded
        // as the system's.
        $actor = $this->scope->auditActor();

        try {
            $copied = $context->runAs($target, fn (): RegisteredClient => $registry->import($blueprint, null, null, $actor));
        } catch (InvalidClientMetadata|ScopeNotGrantable $refused) {
            return back()->withInput()->with('error', 'Not copied: '.lcfirst(strtr($refused->getMessage(), [
                'Invalid client blueprint: ' => '',
                'api_key_prefix' => 'the key prefix',
                'backchannel_logout_uri' => 'the logout URI',
                '; import with another prefix (or none)' => ' — clear it on the Settings tab of either app, then copy again',
            ])));
        }

        // The plaintext exists only in this response, on the flash channel — see the
        // reveal on the app page for why never in props.
        $this->inertia->flash('copiedApp', [
            'environment' => $target->name,
            'name' => $copied->client->name,
            'issuer' => rtrim($issuers->forEnvironment($target->id), '/'),
            'clientId' => $copied->client->client_id,
            'secret' => $copied->secret,
        ]);

        return back()->with('status', "Copied to {$target->name}. Copy its credentials now — the secret will not be shown again.");
    }
}
