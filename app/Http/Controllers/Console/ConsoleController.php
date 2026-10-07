<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Controllers\PageController;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ShellPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * WHAT EVERY CONSOLE PAGE HAS IN COMMON: a title, and a plane.
 *
 * THE TITLE IS THE SERVER'S. It is rendered into `<title>` on the first byte rather than
 * set by the page's own `<Head>` after the bundle parses — otherwise the first paint of
 * every console page says nothing but the product's name, and a person restoring twenty
 * tabs gets twenty identical ones. It is stated once, here, and the client's title
 * callback reads the same prop, so the two cannot disagree.
 *
 * THE PLANE IS THE SERVER'S TOO. The two planes call the same capability by different
 * route names — `webhooks` and `environment.webhooks` — and a page is one file. It could
 * do the arithmetic itself, but then a rename would mean a button posting to the other
 * plane's URL, and nothing would say so. {@see self::url()} answers instead.
 */
abstract readonly class ConsoleController extends PageController
{
    public function __construct(
        ResponseFactory $inertia,
        protected ConsoleScope $scope,
    ) {
        parent::__construct($inertia);
    }

    /**
     * Render a console page, titled and placed.
     *
     * The SECTION is the console's addition to the base: the word that distinguishes the
     * whole install from one customer on it. Half the platform pages share a name with a
     * page about the operator's own organization — "Usage" is this install's traffic in
     * one and one customer's bill in the other — and the platform section used to have a
     * shell of its own that said so in the tab.
     *
     * @param  array<string, mixed>  $props
     */
    protected function page(string $component, string $title, array $props = []): Response
    {
        return parent::page($component, $title, $props)
            ->withViewData(['section' => app(ShellPayload::class)->build()?->section]);
    }

    /**
     * A URL on THIS plane, by the route's organization-plane name.
     *
     * {@see ConsoleScope::routeName()} maps it; the environment plane prefixes the same
     * capability with `environment.`.
     *
     * @param  array<string, mixed>|string|null  $parameters
     */
    protected function url(string $name, array|string|null $parameters = null): string
    {
        $route = $this->scope->routeName($name);

        return $parameters === null ? route($route) : route($route, $parameters);
    }

    /**
     * The organization this page acts within, or null for the whole-environment view.
     *
     * Null has to mean "an environment administrator has not chosen one yet" and never
     * "this member has no organization" — the nullable reader answers null for both, and
     * on the organization plane that second case widens every scoped query on the page
     * from one organization to the whole environment.
     */
    protected function actingOrganizationId(): ?string
    {
        return $this->scope->plane() === ConsolePlane::Environment
            ? $this->scope->organizationId()
            : $this->scope->requireOrganizationId();
    }

    /**
     * Run an action as the person signed in — the same action, rules and audit entry the
     * management API and MCP run — and turn a refusal into what a form expects: back, with
     * the message on the field it is about.
     *
     * `$fields` maps the action's input names to this page's form field names
     * (`client_id` → `clientId`); a refusal about a field the page does not have lands on
     * `$fallback`. `$messages` lets a page say a refusal in its own words — keyed by the
     * refusal's code — where the API's sentence is written for a machine's caller. A 404 is
     * a 404.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $messages
     */
    protected function act(string $action, array $input, array $fields = [], string $fallback = 'form', array $messages = []): ActionResult|RedirectResponse
    {
        try {
            return $this->runAction($action, $input);
        } catch (ActionRefused $refused) {
            abort_if($refused->status === 404, 404);

            $field = $refused->field === null ? $fallback : ($fields[$refused->field] ?? $fallback);

            return back()->withInput()->withErrors([$field => $messages[$refused->error] ?? $refused->getMessage()]);
        } catch (ValidationException $invalid) {
            $errors = [];

            foreach ($invalid->errors() as $field => $lines) {
                $first = is_array($lines) ? ($lines[0] ?? null) : null;
                $errors[$fields[$field] ?? $fallback] = is_string($first) ? $first : $invalid->getMessage();
            }

            return back()->withInput()->withErrors($errors);
        }
    }

    /**
     * Run an action as the person signed in and let a refusal through, for a page whose
     * answer to one is not a form error — a quiet no-op, a flash on the button that asked.
     * {@see self::act()} is the form-shaped version.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     * @throws ValidationException
     */
    protected function runAction(string $action, array $input): ActionResult
    {
        return app(ActionRunner::class)->run($action, new ConsoleSessionPrincipal($this->scope), $input);
    }
}
