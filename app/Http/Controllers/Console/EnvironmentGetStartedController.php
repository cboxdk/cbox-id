<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Apps\CreateApp;
use App\Http\Props\Shared\HelpProps;
use App\Platform\Actions\ActionRefused;
use App\Platform\Connect\ConnectSnippets;
use App\Platform\Connect\QuickstartFramework;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Help\HelpTopic;
use App\Platform\Onboarding\EnvironmentChecklist;
use App\Support\CliClient;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\RegisteredClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * ENVIRONMENT CONSOLE › HOME › GET STARTED — from nothing to somebody signed in.
 *
 *  1. PICK A FRAMEWORK. The one question: it decides the app's kind (a React app cannot keep
 *     a secret) and its localhost redirect (the framework's own dev-server port).
 *  2. THE APP IS CREATED through the `apps.create` action — the same action, rules and audit
 *     entry as "New app", the REST API and MCP — and the page shows the `.env` block, the
 *     install command and the code for that framework ({@see ConnectSnippets::quickstart()}).
 *     A confidential app's secret arrives once, on the flash channel, never in the props.
 *  3. "WAITING FOR YOUR FIRST SIGN-IN…" The page polls (a partial reload of `signedIn`)
 *     until a token has been issued to a person for that app, then says so.
 *
 * Beneath it, the environment's checklist ({@see EnvironmentChecklist}): what is done is
 * read off the environment, never ticked.
 */
final readonly class EnvironmentGetStartedController extends ConsoleController
{
    public function index(Request $request, EnvironmentChecklist $checklist, ConnectSnippets $snippets, IssuerResolver $issuer): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $framework = QuickstartFramework::tryFrom($request->string('framework')->toString());
        $app = $this->app($request->string('app')->toString());
        $issuerUrl = rtrim($issuer->issuer(), '/');
        $subject = app(EnvironmentAdminAuth::class)->subjectId();

        return $this->page('environment/get-started', 'Get started', [
            'help' => HelpProps::for(HelpTopic::EnvironmentGetStarted),
            'checklist' => $checklist->toProps($this->workspaceId()),
            'dismissed' => $subject !== null && $checklist->isDismissed($subject),
            'frameworks' => array_map(static fn (QuickstartFramework $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
                'redirectUri' => $option->redirectUri(),
                'appKind' => $option->kind()->value,
                'kind' => $option->kind()->label(),
            ], QuickstartFramework::cases()),
            'framework' => $framework?->value,
            'createdApp' => $app === null ? null : [
                'id' => $app->id,
                'name' => $app->name,
                'clientId' => $app->client_id,
                'href' => route('environment.clients.show', $app->id),
            ],
            'quickstart' => $app === null || $framework === null ? null : $snippets->quickstart($framework, $app, $issuerUrl)->toArray(),
            'secretPlaceholder' => ConnectSnippets::SECRET_PLACEHOLDER,
            // Polled by the page as a partial reload until it turns true.
            'signedIn' => $app !== null && $checklist->signedIn($app->client_id),
            'urls' => [
                'createApp' => route('environment.get-started.app'),
                'dismiss' => route('environment.get-started.dismiss'),
                'restore' => route('environment.get-started.restore'),
                'home' => route('environment.home'),
            ],
        ]);
    }

    /** Step 2: create the app for the framework picked, through `apps.create`. */
    public function createApp(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $framework = QuickstartFramework::tryFrom($request->string('framework')->toString());

        if ($framework === null) {
            return back()->withErrors(['framework' => 'Choose what you are building.']);
        }

        // The same step-up "New app" asks for: this mints a secret shown once.
        $sudo = app(ConsoleStepUp::class)->challenge(
            'clients.create',
            'environment.get-started',
            ['framework' => $framework->value],
            'Creating an app issues a client secret, shown once on the next screen — it signs in as this app until it is rotated.',
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $name = trim($request->string('name')->toString());

        $result = $this->attempt(CreateApp::class, [
            'name' => $name !== '' ? $name : 'My '.$framework->label().' app',
            'type' => $framework->kind()->value,
            'redirect_uris' => [$framework->redirectUri()],
            'organization_id' => null,
        ], ['name' => 'name', 'redirect_uris' => 'framework'], 'framework');

        if ($result instanceof ActionRefused) {
            return back()->withInput()->withErrors(['framework' => $result->getMessage()]);
        }

        /** @var RegisteredClient $registered */
        $registered = $result->value;

        // On the flash channel, never in props: see ClientController::store().
        if ($registered->secret !== null && $registered->secret !== '') {
            $this->inertia->flash('revealedSecret', $registered->secret);
        }

        return to_route('environment.get-started', ['framework' => $framework->value, 'app' => $registered->client->id])
            ->with('status', 'App "'.$registered->client->name.'" created.');
    }

    /** Put the checklist away — for this person, in this environment. */
    public function dismiss(EnvironmentChecklist $checklist): RedirectResponse
    {
        $subject = app(EnvironmentAdminAuth::class)->subjectId() ?? abort(403);
        $checklist->dismiss($subject);

        return to_route('environment.home');
    }

    /** Bring it back. */
    public function restore(EnvironmentChecklist $checklist): RedirectResponse
    {
        $subject = app(EnvironmentAdminAuth::class)->subjectId() ?? abort(403);
        $checklist->restore($subject);

        return to_route('environment.get-started');
    }

    /** The app the quickstart made — one of this environment's, never the CLI's own. */
    private function app(string $id): ?Client
    {
        if ($id === '') {
            return null;
        }

        return Client::query()->whereKey($id)->where('name', '!=', CliClient::NAME)->first();
    }

    private function workspaceId(): ?string
    {
        $organizationId = app(EnvironmentAdminAuth::class)->membership()?->organization_id;

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
